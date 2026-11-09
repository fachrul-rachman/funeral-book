<?php

use App\Jobs\SendWhatsAppDelivery;
use App\Models\User;
use App\Services\FuneralRequests;
use App\Services\WhatsAppDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'funeral.whatsapp.enabled' => true,
        'funeral.whatsapp.version' => 'v25.0',
        'funeral.whatsapp.phone_id' => '123456',
        'funeral.whatsapp.access_token' => 'token-fixture',
        'funeral.whatsapp.app_secret' => 'secret-fixture',
        'funeral.whatsapp.verify_token' => 'verify-fixture',
        'funeral.whatsapp.test_recipient' => '081234567890',
        'funeral.whatsapp.languages.new_request' => 'id',
        'funeral.whatsapp.languages.completed' => 'id',
    ]);
    Queue::fake();
    Http::preventStrayRequests();
});

function whatsappIntent(): object
{
    User::factory()->create(['role' => 'pic', 'active' => true, 'phone' => '+6281234567890']);
    app(FuneralRequests::class)->submit([
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567891',
        'relationship_to_deceased' => 'Keluarga', 'deceased_name' => 'Nama Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Lokasi Uji',
        'has_plot' => false, 'estimated_burial_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => true,
    ]);

    return DB::table('notification_deliveries')->first();
}

test('one intent sends once and Meta acceptance is recorded', function () {
    $row = whatsappIntent();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
    $job = new SendWhatsAppDelivery($row->id);
    $job->handle(app(WhatsAppDelivery::class));
    $job->handle(app(WhatsAppDelivery::class));
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['to'] === '6281234567890'
        && $request['biz_opaque_callback_data'] === $row->id
        && $request['template']['language']['code'] === 'id'
        && $request['template']['components'][1] === [
            'type' => 'button', 'sub_type' => 'url', 'index' => '0',
            'parameters' => [['type' => 'text', 'text' => 'pengajuan/'.$row->funeral_request_id]],
        ]
        && count($request['template']['components'][0]['parameters']) === 12
        && $request['template']['components'][0]['parameters'][4]['text'] === 'Nama Uji'
        && $request['template']['components'][0]['parameters'][6]['text'] === 'Lokasi Uji'
        && $request['template']['components'][0]['parameters'][7]['text'] === 'Belum diisi'
        && $request['template']['components'][0]['parameters'][9]['text'] === '10.00'
        && $request['template']['components'][0]['parameters'][10]['text'] === 'Belum memiliki lahan'
        && $request['template']['components'][0]['parameters'][11]['text'] === 'Belum diisi');
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'accepted', 'attempt_count' => 1, 'provider_message_id' => 'wamid.test']);
});

test('transient rejections retry with a limit while an ambiguous outcome does not resend', function () {
    $row = whatsappIntent();
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push([], 429)->push([], 502)]);
    $service = app(WhatsAppDelivery::class);
    $service->send($row->id);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'pending', 'attempt_count' => 1]);
    $service->send($row->id);
    Http::assertSentCount(1);
    $this->travel(15)->minutes();
    $service->send($row->id);
    $service->send($row->id);
    Http::assertSentCount(2);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'unknown', 'attempt_count' => 2]);
});

test('the webhook authenticates its raw body, reconciles uncertainty and ignores downgrades', function () {
    $row = whatsappIntent();
    DB::table('notification_deliveries')->where('id', $row->id)->update(['state' => 'unknown', 'attempt_count' => 1]);
    $url = '/api/v1/webhooks/whatsapp';
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')->assertForbidden();
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-fixture&hub.challenge=123')->assertOk()->assertSeeText('123');
    $payload = fn ($status) => json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [[
        'field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123456'], 'statuses' => [[
            'id' => 'wamid.recovered', 'status' => $status, 'biz_opaque_callback_data' => $row->id,
        ]]],
    ]]]]], JSON_THROW_ON_ERROR);
    $post = fn ($status, $sign = true) => $this->call('POST', $url, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $sign ? 'sha256='.hash_hmac('sha256', $payload($status), 'secret-fixture') : 'sha256=wrong',
    ], content: $payload($status));

    $post('read', false)->assertForbidden();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'unknown']);
    $post('read')->assertOk();
    $post('sent')->assertOk();
    $post('read')->assertOk();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'read', 'provider_message_id' => 'wamid.recovered']);
});

test('disabled sending and recipients outside an explicit restriction stay pending', function () {
    config(['funeral.whatsapp.test_recipient' => '081234567899']);
    $row = whatsappIntent();
    Queue::assertNothingPushed();
    app(WhatsAppDelivery::class)->send($row->id);
    config(['funeral.whatsapp.test_recipient' => '081234567890', 'funeral.whatsapp.enabled' => false]);
    app(WhatsAppDelivery::class)->recover();
    app(WhatsAppDelivery::class)->send($row->id);
    Http::assertNothingSent();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'pending', 'attempt_count' => 0]);
});

test('an empty recipient restriction permits delivery in every environment', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);
    config(['funeral.whatsapp.test_recipient' => '']);
    $row = whatsappIntent();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.unrestricted']]])]);
    app(WhatsAppDelivery::class)->send($row->id);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'accepted']);
})->with(['local', 'production']);

test('an invalid nonempty recipient restriction never permits a send', function () {
    config(['funeral.whatsapp.test_recipient' => 'invalid']);
    $row = whatsappIntent();
    app(WhatsAppDelivery::class)->send($row->id);
    Http::assertNothingSent();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'pending', 'attempt_count' => 0]);
});

test('the outbox dispatches only after commit and recovers an interrupted attempt without resending', function () {
    $row = whatsappIntent();
    Queue::assertPushedOn('whatsapp', SendWhatsAppDelivery::class, fn ($job) => $job->deliveryId === $row->id);
    DB::table('notification_deliveries')->where('id', $row->id)->update([
        'state' => 'processing', 'attempt_count' => 1, 'processing_started_at' => now()->utc()->subMinutes(3),
    ]);
    $service = app(WhatsAppDelivery::class);
    $service->recover();
    $service->send($row->id);
    Http::assertNothingSent();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'unknown', 'last_error_code' => 'processing_expired']);
});

test('permanent failures stop and transient rejections honor retry hints and stop after three attempts', function () {
    $row = whatsappIntent();
    $service = app(WhatsAppDelivery::class);
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 132001]], 400)->push([], 429, ['Retry-After' => '120'])->push([], 429)->push([], 429)]);
    $service->send($row->id);
    $service->send($row->id);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'failed', 'last_error_code' => '132001']);

    $this->freezeTime();
    DB::table('notification_deliveries')->where('id', $row->id)->update(['state' => 'pending', 'attempt_count' => 0]);
    $service->send($row->id);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'pending', 'next_attempt_at' => now()->utc()->addSeconds(120)]);
    $this->travel(119)->seconds();
    $service->send($row->id);
    Http::assertSentCount(2);
    $this->travel(2)->minutes();
    $service->send($row->id);
    $this->travel(2)->minutes();
    $service->send($row->id);
    $service->send($row->id);
    Http::assertSentCount(4);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'failed', 'attempt_count' => 3]);
});

test('a rolled back business transaction does not dispatch WhatsApp work', function () {
    expect(fn () => DB::transaction(function () {
        whatsappIntent();
        Queue::assertNothingPushed();
        throw new RuntimeException('Rollback test');
    }))->toThrow(RuntimeException::class, 'Rollback test');

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('only monitoring roles see delivery alerts without phone numbers or provider payloads', function () {
    $this->withoutVite();
    $row = whatsappIntent();
    DB::table('notification_deliveries')->where('id', $row->id)->update([
        'state' => 'unknown', 'last_error_code' => 'connection_uncertain',
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => 'admin']);
    $this->post('/masuk', ['phone' => $admin->phone, 'password' => 'password'])->assertRedirect('/staf');
    $this->get('/staf')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('deliveryAlerts.total', 1)
        ->where('deliveryAlerts.data.0.last_error_code', 'connection_uncertain')
        ->missing('deliveryAlerts.data.0.destination_e164'));
    $this->post('/keluar');

    $this->post('/masuk', ['phone' => $row->destination_e164, 'password' => 'password'])->assertRedirect('/staf');
    $this->get('/staf')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('deliveryAlerts.total', 1)->where('deliveryAlerts.data.0.source', 'whatsapp'));
});

test('PIC may retry their own staff notification and repeat clicks cannot enqueue another copy', function () {
    $this->withoutVite();
    $row = whatsappIntent();
    $pic = User::where('phone', $row->destination_e164)->firstOrFail();
    DB::table('notification_deliveries')->where('id', $row->id)->update(['state' => 'failed', 'attempt_count' => 3, 'last_error_code' => '132000']);
    config(['funeral.templates.new_request' => 'newly_approved_template']);
    $url = '/staf/pengajuan/'.$row->funeral_request_id.'/notifikasi/'.$row->id.'/kirim-ulang';
    $this->post('/masuk', ['phone' => $pic->phone, 'password' => 'password']);
    $this->post($url)->assertRedirect('/staf/pengajuan/'.$row->funeral_request_id);
    $replacement = DB::table('notification_deliveries')->where('id', '<>', $row->id)->first();
    expect($replacement->template_name)->toBe('newly_approved_template')->and($replacement->destination_e164)->toBe($row->destination_e164);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'failed', 'superseded_by' => $replacement->id]);
    $this->assertDatabaseHas('request_audit_logs', ['funeral_request_id' => $row->funeral_request_id, 'actor_user_id' => $pic->id, 'action' => 'notification_retry_requested']);
    $this->post($url)->assertConflict();
    $this->post($url, [], ['X-Inertia' => 'true'])->assertConflict()
        ->assertHeader('X-Inertia-Location', url('/staf/pengajuan/'.$row->funeral_request_id));
    $this->assertDatabaseCount('notification_deliveries', 2);
    Queue::assertPushed(SendWhatsAppDelivery::class, fn ($job) => $job->deliveryId === $replacement->id);
    Http::assertNothingSent();
});

test('unknown sends require explicit duplicate-risk acknowledgement and accepted messages cannot be retried', function () {
    $this->withoutVite();
    $row = whatsappIntent();
    $pic = User::where('phone', $row->destination_e164)->firstOrFail();
    $url = '/staf/pengajuan/'.$row->funeral_request_id.'/notifikasi/'.$row->id.'/kirim-ulang';
    DB::table('notification_deliveries')->where('id', $row->id)->update(['state' => 'unknown', 'attempt_count' => 1]);
    $this->post('/masuk', ['phone' => $pic->phone, 'password' => 'password']);
    $this->post($url)->assertSessionHasErrors('retry');
    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->post($url, ['acknowledge_uncertain' => true])->assertRedirect();
    $replacementId = DB::table('notification_deliveries')->where('id', $row->id)->value('superseded_by');
    DB::table('notification_deliveries')->where('id', $replacementId)->update(['state' => 'accepted', 'provider_message_id' => 'wamid.already-accepted']);
    $this->post(str_replace($row->id, $replacementId, $url))->assertConflict();
    $this->assertDatabaseCount('notification_deliveries', 2);
    Http::assertNothingSent();
});
