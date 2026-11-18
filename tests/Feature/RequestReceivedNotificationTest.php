<?php

use App\Jobs\SendWhatsAppDelivery;
use App\Models\User;
use App\Services\FuneralRequests;
use App\Services\WhatsAppDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    config([
        'funeral.whatsapp.enabled' => true,
        'funeral.whatsapp.version' => 'v25.0',
        'funeral.whatsapp.phone_id' => '123456',
        'funeral.whatsapp.access_token' => 'token-fixture',
        'funeral.whatsapp.test_recipient' => '',
        'funeral.turnstile.secret' => 'test-secret',
        'funeral.turnstile.hostname' => 'localhost',
    ]);
    $this->payload = [
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Anak', 'deceased_name' => 'Almarhum Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Lokasi Uji',
        'has_plot' => false, 'estimated_burial_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => true, 'turnstile_token' => 'test-token',
    ];
});

test('a replay queues one customer receipt with one applicant variable and no button or visitation dependency', function () {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'funeral_submit']),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.received']]]),
    ]);
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson('/api/v1/funeral-requests', $this->payload, $headers)->assertCreated();
    $this->postJson('/api/v1/funeral-requests', $this->payload, $headers)->assertCreated();
    $row = DB::table('notification_deliveries')->where('event_type', 'request_received')->sole();
    Queue::assertPushedOn('whatsapp', SendWhatsAppDelivery::class, fn ($job) => $job->deliveryId === $row->id);
    DB::table('funeral_requests')->where('id', $row->funeral_request_id)->update(['applicant_name' => 'Nama Diubah']);
    $service = app(WhatsAppDelivery::class);
    $service->send($row->id);
    $service->send($row->id);
    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/123456/messages'
        && $request['to'] === '6281234567890'
        && $request['template'] === [
            'name' => 'funeral_request_received', 'language' => ['code' => 'id'],
            'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Pemohon Uji']]]],
        ]);
    Http::assertSentCount(2);
    $this->assertDatabaseCount('visitation_plan_requests', 0);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'accepted', 'attempt_count' => 1]);
});

test('customer receipt failure preserves the request and only its handler or manager may retry the snapshot', function () {
    app(FuneralRequests::class)->submit($this->payload);
    $row = DB::table('notification_deliveries')->where('event_type', 'request_received')->sole();
    $service = app(WhatsAppDelivery::class);
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 132001]], 400)
        ->push(['messages' => [['id' => 'wamid.received.retry']]])]);
    $service->send($row->id);
    $this->assertDatabaseHas('funeral_requests', ['id' => $row->funeral_request_id, 'status' => 'new_request']);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'failed']);
    $this->withoutVite();
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->actingAs($manager)->withSession(['staff_access_version' => $manager->access_version])
        ->get('/staf/pengajuan/'.$row->funeral_request_id)->assertInertia(fn (Assert $page) => $page
        ->where('notificationFailures.0.event_type', 'request_received')
        ->where('notificationFailures.0.can_retry', true));
    $pic = User::factory()->create(['role' => 'pic', 'active' => true]);
    $other = User::factory()->create(['role' => 'pic', 'active' => true, 'phone' => $row->destination_e164]);
    DB::table('funeral_requests')->where('id', $row->funeral_request_id)->update([
        'responsible_user_id' => $pic->id, 'status' => 'contacted', 'applicant_name' => 'Nama Diubah',
    ]);
    $this->actingAs($pic)->withSession(['staff_access_version' => $pic->access_version])->get('/staf')
        ->assertInertia(fn (Assert $page) => $page->where('deliveryAlerts.total', 1));
    $this->actingAs($other)->withSession(['staff_access_version' => $other->access_version])->get('/staf')
        ->assertInertia(fn (Assert $page) => $page->where('deliveryAlerts', null));
    expect(fn () => $service->retryNotification($other, $row->funeral_request_id, $row->id))->toThrow(HttpException::class);
    $service->retryNotification($pic, $row->funeral_request_id, $row->id);
    $retryId = DB::table('notification_deliveries')->where('id', $row->id)->value('superseded_by');
    $service->send($retryId);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['template']['components'] === [
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Pemohon Uji']]],
    ]);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $retryId, 'state' => 'accepted']);
    $this->assertDatabaseCount('visitation_plan_requests', 0);
});

test('receipt delivery honors the existing restricted test recipient', function () {
    config(['funeral.whatsapp.test_recipient' => '081234567899']);
    app(FuneralRequests::class)->submit($this->payload);
    $row = DB::table('notification_deliveries')->where('event_type', 'request_received')->sole();
    app(WhatsAppDelivery::class)->send($row->id);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $row->id, 'state' => 'pending', 'attempt_count' => 0]);
});

test('the receipt migration preserves historical delivery rows and refuses destructive rollback', function () {
    User::factory()->create(['role' => 'pic', 'active' => true]);
    app(FuneralRequests::class)->submit($this->payload);
    $staff = DB::table('notification_deliveries')->where('event_type', 'new_request')->sole();
    $receipt = DB::table('notification_deliveries')->where('event_type', 'request_received')->sole();
    $migration = require database_path('migrations/2026_11_18_000001_add_request_received_notification_event.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $receipt->id]);
    DB::table('notification_deliveries')->where('id', $receipt->id)->delete();
    $migration->down();
    $migration->up();
    DB::table('notification_deliveries')->insert((array) $receipt);
    expect((array) DB::table('notification_deliveries')->where('id', $staff->id)->first())->toBe((array) $staff);
    $this->assertDatabaseCount('notification_deliveries', 2);
});
