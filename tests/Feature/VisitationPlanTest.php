<?php

use App\Models\User;
use App\Services\FuneralRequests;
use App\Services\VisitationPlans;
use App\Services\WhatsAppDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    config([
        'funeral.visitation.enabled' => true, 'funeral.visitation.url' => 'https://visitation.example.test/api/visitation-plans',
        'funeral.visitation.key' => 'secret-fixture',
        'funeral.whatsapp.enabled' => true, 'funeral.whatsapp.version' => 'v25.0', 'funeral.whatsapp.phone_id' => '1234',
        'funeral.whatsapp.access_token' => 'wa-fixture', 'funeral.whatsapp.test_recipient' => '081234567890',
        'funeral.whatsapp.languages.completed' => 'id',
    ]);
});

function completedVisitationRequest(array $overrides = []): string
{
    $service = app(FuneralRequests::class);
    $reference = $service->submit($overrides + [
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Anak', 'deceased_name' => 'Almarhum Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Rumah Duka Uji',
        'has_plot' => true, 'burial_site' => 'karawang', 'zone' => 'A', 'lot_number' => '123',
        'paid_in_full' => true, 'estimated_burial_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        'estimated_burial_time' => '12:00', 'consent_accepted' => true,
    ]);
    $id = DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
    $actor = User::factory()->create(['active' => true]);
    $photo = fn () => UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzQAAAAASUVORK5CYII='));
    $service->transition($actor, $id, 'new_request', 'contacted', $photo());
    $service->transition($actor, $id, 'contacted', 'in_progress');
    foreach (['ownership_certificate', 'plot_owner_ktp', 'deceased_ktp', 'hospital_death_certificate'] as $type) {
        $service->attach($actor, $id, $type, $photo());
    }
    $service->transition($actor, $id, 'in_progress', 'completed');

    return $id;
}

test('completion waits for the visitation URL then sends a name body and a dynamic URL button', function () {
    $id = completedVisitationRequest();
    $delivery = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    DB::table('notification_deliveries')->where('id', $delivery->id)->update([
        'template_parameters' => json_encode(['Legacy name', 'https://visitation.example.test/plan/legacy']),
    ]);
    app(WhatsAppDelivery::class)->send($delivery->id);
    Http::assertNothingSent();
    Http::fake([
        'visitation.example.test/*' => Http::response(['url' => 'https://visitation.example.test/plan/unique-fixture?source=funeral', 'expires_at' => now()->addDays(5)->toIso8601String()], 201),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.visitation']]]),
    ]);
    app(VisitationPlans::class)->create($id);
    app(WhatsAppDelivery::class)->send($delivery->id);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-fixture')
        && $request['request_reference'] === DB::table('funeral_requests')->where('id', $id)->value('reference_code')
        && $request['applicant']['whatsapp'] === '081234567890'
        && $request['deceased']['date_of_death'] === '2025-01-01'
        && $request['burial']['scheduled_at'] === now('Asia/Jakarta')->addDay()->format('Y-m-d').'T12:00:00+07:00'
        && $request['burial']['zone'] === 'A' && $request['burial']['lot_number'] === '123');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && count($request['template']['components']) === 2
        && array_column($request['template']['components'][0]['parameters'], 'text') === ['Almarhum Uji']
        && $request['template']['components'][1] === [
            'type' => 'button', 'sub_type' => 'url', 'index' => '0',
            'parameters' => [['type' => 'text', 'text' => 'plan/unique-fixture?source=funeral']],
        ]);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'completed']);
    $this->assertDatabaseHas('visitation_plan_requests', ['funeral_request_id' => $id, 'state' => 'ready']);
});

test('a visitation URL without a reservation path cannot be sent as a customer button', function () {
    $id = completedVisitationRequest();
    Http::fake(['visitation.example.test/*' => Http::response([
        'url' => 'https://visitation.example.test/', 'expires_at' => now()->addDays(5)->toIso8601String(),
    ], 201)]);
    app(VisitationPlans::class)->create($id);
    $delivery = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    app(WhatsAppDelivery::class)->send($delivery->id);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'state' => 'failed', 'last_error_code' => 'visitation_url_invalid']);
});

test('an uncertain API outcome can retry the identical reference and payload and reuse a 200 URL', function () {
    $id = completedVisitationRequest();
    Http::fake(['visitation.example.test/*' => Http::sequence()
        ->pushStatus(503)
        ->push(['url' => 'https://visitation.example.test/plan/same-fixture', 'expires_at' => now()->addDays(5)->toIso8601String()], 200)]);
    $service = app(VisitationPlans::class);
    $service->create($id);
    $this->assertDatabaseHas('visitation_plan_requests', ['funeral_request_id' => $id, 'state' => 'pending']);
    $this->travel(20)->minutes();
    $service->create($id);
    $service->create($id);
    $sent = Http::recorded();
    expect($sent)->toHaveCount(2)->and($sent[0][0]->data())->toBe($sent[1][0]->data());
});

test('expired links and permanent API errors do not release a customer message', function (int $status, array $response, string $code) {
    $id = completedVisitationRequest();
    Http::fake(['visitation.example.test/*' => Http::response($response, $status)]);
    app(VisitationPlans::class)->create($id);
    $delivery = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    app(WhatsAppDelivery::class)->send($delivery->id);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('visitation_plan_requests', ['funeral_request_id' => $id, 'state' => 'failed', 'last_error_code' => $code]);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'state' => 'pending', 'attempt_count' => 0]);
})->with([
    'authentication' => [401, ['message' => ''], 'visitation_http_401'],
    'conflict' => [409, ['message' => 'Private fixture error'], 'visitation_http_409'],
    'validation' => [422, ['message' => 'Private fixture error'], 'visitation_http_422'],
    'expired' => [200, ['url' => 'https://visitation.example.test/plan/expired', 'expires_at' => '2020-01-01T00:00:00+07:00'], 'visitation_link_expired'],
]);

test('interrupted API attempts recover with bounded retries and visible failures', function () {
    $this->withoutVite();
    $id = completedVisitationRequest();
    DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->update([
        'state' => 'processing', 'attempt_count' => 4, 'processing_started_at' => now()->subMinutes(3),
    ]);
    $service = app(VisitationPlans::class);
    $service->recover();
    Http::fake(['visitation.example.test/*' => Http::failedConnection()]);
    $service->create($id);
    $service->recover();
    $service->create($id);
    $this->assertDatabaseHas('visitation_plan_requests', [
        'funeral_request_id' => $id, 'state' => 'failed', 'attempt_count' => 5,
        'last_error_code' => 'visitation_connection_failed',
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
    $this->post('/masuk', ['phone' => $admin->phone, 'password' => 'password']);
    $this->get('/staf')->assertInertia(fn (Assert $page) => $page
        ->has('deliveryAlerts.data', 1)->where('deliveryAlerts.data.0.source', 'visitation'));
    $this->get('/staf/pengajuan/'.$id)->assertInertia(fn (Assert $page) => $page
        ->where('visitation.state', 'failed')->missing('visitation.payload')->missing('visitation.url'));
});

test('malformed success retries and a link expiring before WhatsApp is held visibly', function () {
    $id = completedVisitationRequest();
    Http::fake(['visitation.example.test/*' => Http::sequence()
        ->push(['url' => 'http://unsafe.example.test/link', 'expires_at' => 'invalid'], 201)
        ->push(['url' => 'https://visitation.example.test/plan/short', 'expires_at' => now()->addMinutes(30)->toIso8601String()], 201)]);
    $service = app(VisitationPlans::class);
    $service->create($id);
    $this->assertDatabaseHas('visitation_plan_requests', ['state' => 'pending', 'last_error_code' => 'visitation_response_invalid']);
    $this->travel(1)->minutes();
    $service->create($id);
    $this->travel(1)->hours();
    $service->recover();
    $delivery = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    app(WhatsAppDelivery::class)->send($delivery->id);
    Http::assertSentCount(2);
    $this->assertDatabaseHas('visitation_plan_requests', ['state' => 'failed', 'last_error_code' => 'visitation_link_expired']);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'attempt_count' => 0]);
});

test('zone is separate from lot and discarded when no plot is owned', function () {
    $service = app(FuneralRequests::class);
    $id = completedVisitationRequest(['zone' => '0', 'lot_number' => '0007']);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'zone' => '0', 'lot_number' => '0007']);
    $data = $service->submissionFields([
        'applicant_name' => 'Uji', 'applicant_whatsapp' => '081234567890', 'relationship_to_deceased' => 'Anak',
        'deceased_name' => 'Uji', 'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Uji',
        'has_plot' => false, 'zone' => 'STALE', 'lot_number' => 'STALE',
        'estimated_burial_date' => now()->addDay()->toDateString(), 'estimated_burial_time' => '12:00', 'consent_accepted' => true,
    ]);
    expect($data['zone'])->toBeNull()->and($data['lot_number'])->toBeNull();
});

test('burial validation returns a request to its handler for correction and recompletion sends once', function () {
    $this->withoutVite();
    $id = completedVisitationRequest();
    $request = DB::table('funeral_requests')->where('id', $id)->first();
    $actor = User::findOrFail($request->responsible_user_id);
    $oldGeneration = DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->value('generation');
    Http::fake([
        'visitation.example.test/*' => Http::sequence()
            ->push(['errors' => ['burial.zone' => ['Untrusted provider text'], 'burial.lot_number' => ['Private provider text']]], 422)
            ->push(['url' => 'https://visitation.example.test/plan/corrected', 'expires_at' => now()->addDays(5)->toIso8601String()], 201),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.corrected']]]),
    ]);
    $plans = app(VisitationPlans::class);
    $plans->create($id);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'in_progress', 'completed_at' => null,
        'responsible_user_id' => $actor->id, 'first_contacted_at' => $request->first_contacted_at]);
    expect(DB::table('request_documents')->where('funeral_request_id', $id)->count())->toBe(5);
    $this->assertDatabaseMissing('notification_deliveries', ['funeral_request_id' => $id, 'event_type' => 'completed']);
    $this->post('/masuk', ['phone' => $actor->phone, 'password' => 'password']);
    $this->get('/staf/pengajuan/'.$id)->assertInertia(fn (Assert $page) => $page
        ->where('canEdit', true)->where('visitation.validation_fields', ['burial.zone', 'burial.lot_number']));
    $this->get('/staf')->assertInertia(fn (Assert $page) => $page
        ->where('deliveryAlerts.total', 1)->where('deliveryAlerts.data.0.validation_fields', ['burial.zone', 'burial.lot_number']));
    app(FuneralRequests::class)->edit($actor, $id, ['zone' => 'B', 'lot_number' => '0007']);
    app(FuneralRequests::class)->transition($actor, $id, 'in_progress', 'completed');
    expect(DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->value('generation'))->not->toBe($oldGeneration);
    $plans->create($id);
    $delivery = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    app(WhatsAppDelivery::class)->send($delivery->id);
    app(WhatsAppDelivery::class)->send($delivery->id);
    Http::assertSentCount(3);
    Http::assertSent(fn ($http) => str_contains($http->url(), 'visitation.example.test') && $http['burial']['zone'] === 'B'
        && $http['burial']['lot_number'] === '0007' && $http['request_reference'] === $request->reference_code);
    $this->assertDatabaseHas('request_audit_logs', ['funeral_request_id' => $id, 'action' => 'visitation_correction_required', 'actor_user_id' => null]);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'completed']);
});

test('an old API attempt cannot roll back a newer completion generation', function () {
    $id = completedVisitationRequest();
    Http::fake(function () use ($id) {
        DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->update(['generation' => (string) Str::uuid()]);

        return Http::response(['errors' => ['burial.zone' => ['Invalid zone']]], 422);
    });
    app(VisitationPlans::class)->create($id);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'completed']);
    $this->assertDatabaseMissing('request_audit_logs', ['funeral_request_id' => $id, 'action' => 'visitation_correction_required']);
    $this->assertDatabaseHas('visitation_plan_requests', ['funeral_request_id' => $id, 'state' => 'processing']);
});

test('burial validation cannot reopen a request after customer delivery has started', function () {
    $id = completedVisitationRequest();
    DB::table('notification_deliveries')->where('funeral_request_id', $id)->where('event_type', 'completed')
        ->update(['state' => 'accepted', 'attempt_count' => 1, 'provider_message_id' => 'wamid.already-accepted']);
    Http::fake(['visitation.example.test/*' => Http::response(['errors' => ['burial.lot_number' => ['Invalid lot']]], 422)]);
    app(VisitationPlans::class)->create($id);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'completed']);
    $this->assertDatabaseHas('notification_deliveries', ['funeral_request_id' => $id, 'state' => 'accepted']);
    $this->assertDatabaseHas('visitation_plan_requests', ['funeral_request_id' => $id, 'state' => 'failed', 'validation_fields' => null]);
});

test('non-burial validation errors never reopen a completed request', function () {
    $id = completedVisitationRequest();
    Http::fake(['visitation.example.test/*' => Http::response(['errors' => ['applicant.whatsapp' => ['Invalid phone']]], 422)]);
    app(VisitationPlans::class)->create($id);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'completed']);
});

test('a midnight WIB schedule and maximum local phone length reach the API unchanged in meaning', function () {
    $day = now('Asia/Jakarta')->addDay()->toDateString();
    $id = completedVisitationRequest(['estimated_burial_date' => $day, 'estimated_burial_time' => '00:30', 'applicant_whatsapp' => '0812345678901']);
    $stored = DB::table('funeral_requests')->where('id', $id)->first();
    expect($stored->applicant_whatsapp_e164)->toBe('+62812345678901');
    expect(CarbonImmutable::parse($stored->current_burial_at, 'UTC')->format('Y-m-d H:i'))
        ->toBe(CarbonImmutable::parse($day.'T00:30:00+07:00')->utc()->format('Y-m-d H:i'));
    Http::fake(['visitation.example.test/*' => Http::response(['url' => 'https://visitation.example.test/plan/midnight', 'expires_at' => now()->addDays(5)->toIso8601String()], 201)]);
    app(VisitationPlans::class)->create($id);
    Http::assertSent(fn ($http) => $http['burial']['scheduled_at'] === $day.'T00:30:00+07:00' && $http['applicant']['whatsapp'] === '0812345678901');
});

test('a returned request may be cancelled without keeping correction alerts or a customer delivery', function () {
    $this->withoutVite();
    $id = completedVisitationRequest();
    Http::fake(['visitation.example.test/*' => Http::response(['errors' => ['burial.site' => ['Invalid site']]], 422)]);
    app(VisitationPlans::class)->create($id);
    $actor = User::findOrFail(DB::table('funeral_requests')->where('id', $id)->value('responsible_user_id'));
    app(FuneralRequests::class)->transition($actor, $id, 'in_progress', 'cancelled', reason: 'Pembatalan uji');
    $this->post('/masuk', ['phone' => $actor->phone, 'password' => 'password']);
    $this->get('/staf')->assertInertia(fn (Assert $page) => $page->where('deliveryAlerts', null));
    $this->get('/staf/pengajuan/'.$id)->assertInertia(fn (Assert $page) => $page->where('visitation', null)->where('canEdit', false));
    $this->assertDatabaseMissing('notification_deliveries', ['funeral_request_id' => $id, 'event_type' => 'completed']);
});

test('the assigned PIC can retry a failed customer message with the same valid visitation URL', function () {
    $this->withoutVite();
    $id = completedVisitationRequest();
    $owner = User::findOrFail(DB::table('funeral_requests')->where('id', $id)->value('responsible_user_id'));
    Http::fake([
        'visitation.example.test/*' => Http::response(['url' => 'https://visitation.example.test/plan/retry', 'expires_at' => now()->addDays(5)->toIso8601String()], 201),
        'graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 132000]], 400)->push(['messages' => [['id' => 'wamid.retry-success']]]),
    ]);
    app(VisitationPlans::class)->create($id);
    $old = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    app(WhatsAppDelivery::class)->send($old->id);
    $url = '/staf/pengajuan/'.$id.'/notifikasi/'.$old->id.'/kirim-ulang';
    foreach (['admin', 'pic'] as $role) {
        $other = User::factory()->create(['role' => $role, 'active' => true]);
        $this->post('/masuk', ['phone' => $other->phone, 'password' => 'password']);
        $this->post($url)->assertForbidden();
        $this->post('/keluar');
    }
    $this->post('/masuk', ['phone' => $owner->phone, 'password' => 'password']);
    $this->get('/staf/pengajuan/'.$id)->assertInertia(fn (Assert $page) => $page
        ->where('notificationFailures.0.can_retry', true)->missing('notificationFailures.0.destination_e164'));
    config(['funeral.templates.completed' => 'corrected_customer_template']);
    $this->post($url)->assertRedirect();
    $new = DB::table('notification_deliveries')->where('id', $old->id)->value('superseded_by');
    app(WhatsAppDelivery::class)->send($new);
    app(WhatsAppDelivery::class)->send($new);
    Http::assertSentCount(3);
    $this->assertDatabaseHas('notification_deliveries', ['id' => $new, 'state' => 'accepted', 'template_name' => 'corrected_customer_template']);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'completed']);
    $this->assertDatabaseCount('visitation_plan_requests', 1);
});

test('manager retry is refused when the customer reservation URL has expired', function () {
    $id = completedVisitationRequest();
    $delivery = DB::table('notification_deliveries')->where('event_type', 'completed')->first();
    DB::table('notification_deliveries')->where('id', $delivery->id)->update(['state' => 'failed', 'attempt_count' => 1]);
    DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->update(['state' => 'ready', 'url' => 'https://visitation.example.test/plan/expired', 'expires_at' => now()->subMinute()]);
    $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->post('/masuk', ['phone' => $manager->phone, 'password' => 'password']);
    $this->post('/staf/pengajuan/'.$id.'/notifikasi/'.$delivery->id.'/kirim-ulang')->assertSessionHasErrors('retry');
    $this->assertDatabaseCount('notification_deliveries', 1);
    Http::assertNothingSent();
});
