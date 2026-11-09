<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\FuneralRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function staffRequestFixture(): string
{
    return app(FuneralRequests::class)->submit([
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Keluarga', 'deceased_name' => 'Nama Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Lokasi Uji',
        'has_plot' => false, 'estimated_burial_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => true,
    ]);
}

test('the WhatsApp button URL requires staff login and resolves to the protected detail', function () {
    $this->withoutVite();
    $reference = staffRequestFixture();
    $id = DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
    $url = '/pengajuan/'.$id;
    $this->get($url)->assertRedirect('/masuk');
    $pic = User::factory()->create(['role' => 'pic', 'active' => true]);
    $this->post('/masuk', ['phone' => $pic->phone, 'password' => 'password'])->assertRedirect($url);
    $this->get($url)->assertRedirect('/staf/pengajuan/'.$id);
    $this->get('/staf/pengajuan/'.$id)->assertInertia(fn (Assert $page) => $page
        ->component('staff-request')->where('request.reference_code', $reference));
    $this->get('/pengajuan/not-a-uuid')->assertNotFound();
    $this->get('/pengajuan/'.Str::uuid())->assertRedirect();
    $this->get('/staf/pengajuan/'.Str::uuid())->assertNotFound();
    $this->post('/keluar');
    $inactive = User::factory()->create(['active' => false]);
    $this->actingAs($inactive)->get($url)->assertRedirect('/masuk');
});

test('staff dashboard and request detail enforce roles on both reads and writes', function () {
    $this->withoutVite();
    $reference = staffRequestFixture();
    $id = DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
    $url = '/staf/pengajuan/'.$id;
    $this->get($url)->assertRedirect('/masuk');

    $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
    $this->post('/masuk', ['phone' => $admin->phone, 'password' => 'password']);
    $this->get('/staf')->assertInertia(fn (Assert $page) => $page
        ->component('staff')->where('metrics.today', 1)->has('analytics')->missing('requests'));
    $this->get('/staf/pengajuan?status=active')->assertInertia(fn (Assert $page) => $page
        ->component('staff-requests')->has('requests.data', 1)->missing('metrics'));
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('staff-request')->where('request.reference_code', $reference)->where('canEdit', false));
    $this->get('/staf/pengajuan?q=tidak-cocok')->assertInertia(fn (Assert $page) => $page
        ->has('requests.data', 0)->missing('schedule'));
    $this->post($url.'/status', ['expected_status' => 'new_request', 'target' => 'cancelled', 'reason' => 'Uji'])
        ->assertForbidden();
    $this->post('/keluar');

    $pic = User::factory()->create(['active' => true]);
    $this->post('/masuk', ['phone' => $pic->phone, 'password' => 'password']);
    $this->get('/staf')->assertInertia(fn (Assert $page) => $page->where('analytics', null));
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canProcess', true)->where('canEdit', false));
    $this->patch($url, ['current_burial_at' => '2026-11-28T12:00:00+07:00'])->assertForbidden();
    $this->post($url.'/status', ['expected_status' => 'new_request', 'target' => 'contacted'])->assertSessionHasErrors('contact_evidence');
    $payload = ['expected_status' => 'new_request', 'target' => 'cancelled', 'reason' => 'Uji'];
    $this->post($url.'/status', $payload)->assertRedirect($url);
    $this->post($url.'/status', $payload)->assertConflict();
    $this->post($url.'/status', $payload, ['X-Inertia' => 'true'])->assertConflict()
        ->assertHeader('X-Inertia-Location', url($url));
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->where('request.status', 'cancelled'));
});

test('analytics periods isolate metrics and distinguish a zero response from missing data', function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Jakarta'));
    $today = staffRequestFixture();
    $old = staffRequestFixture();
    DB::table('funeral_requests')->where('reference_code', $old)->update([
        'status' => 'contacted', 'submitted_at' => '2026-10-10 11:00:00',
        'first_contacted_at' => '2026-10-10 12:00:00',
        'current_burial_at' => '2026-10-11 03:00:00',
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
    $this->post('/masuk', ['phone' => $admin->phone, 'password' => 'password']);

    $this->get('/staf')->assertInertia(fn (Assert $page) => $page
        ->where('metrics.today', 1)->where('metrics.new_requests', 1)
        ->where('metrics.period_count', 1)->where('metrics.response_seconds', null)
        ->where('analytics.trend.5', 1)->has('schedule', 1));
    $this->get('/staf?period=2026-10')->assertInertia(fn (Assert $page) => $page
        ->where('metrics.today', 1)->where('metrics.new_requests', 1)
        ->where('metrics.period_count', 1)->where('metrics.response_seconds', 0)
        ->where('analytics.trend.9', 1)->has('schedule', 1));
    $this->get('/staf?period=2026-09')->assertInertia(fn (Assert $page) => $page
        ->where('metrics.today', 1)->where('metrics.new_requests', 1)
        ->where('metrics.period_count', 0)->where('metrics.response_seconds', null)
        ->has('schedule', 1));
    $this->get('/staf/pengajuan?status=new_request')->assertInertia(fn (Assert $page) => $page
        ->has('requests.data', 1)->where('requests.data.0.reference_code', $today));
});

test('request details expose an appendable activity timeline and authenticated image thumbnails', function () {
    $this->withoutVite();
    Storage::fake('local');
    $reference = staffRequestFixture();
    $id = DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
    $url = '/staf/pengajuan/'.$id;
    $staff = User::factory()->create(['role' => 'manager', 'active' => true]);
    $this->post('/masuk', ['phone' => $staff->phone, 'password' => 'password']);
    $this->travel(1)->seconds();
    $photo = UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzQAAAAASUVORK5CYII='));
    $this->post($url.'/dokumen', ['type' => 'deceased_ktp', 'document' => $photo])->assertForbidden();
    $this->post($url.'/status', [
        'expected_status' => 'new_request', 'target' => 'contacted', 'contact_evidence' => $photo,
    ])->assertRedirect($url);
    $this->patch($url, ['current_burial_at' => '2026-11-28T12:00:00+07:00'])->assertRedirect($url);
    $this->travel(1)->seconds();
    $this->post($url.'/dokumen', ['type' => 'deceased_ktp', 'document' => $photo])->assertRedirect($url);
    $document = DB::table('request_documents')->where('funeral_request_id', $id)->where('type', 'deceased_ktp')->first();
    $this->travel(1)->seconds();
    $this->get($url.'/dokumen/'.$document->id.'?thumbnail=1')
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('documents.0.detected_mime', 'image/png')
        ->where('canEdit', true)->where('request.current_local', '2026-11-28T12:00')
        ->has('activity.data', 6)
        ->where('activity.data.5.actor', 'Pemohon')
        ->where('activity.data.1.document_type', 'deceased_ktp'));
    $pic = User::factory()->create(['role' => 'pic', 'active' => true]);
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canTransfer', true)
        ->where('handlers', fn ($handlers) => $handlers->pluck('id')->contains($pic->id)));
});

test('manual test recovery is attributed explicitly without impersonating staff or an automatic process', function () {
    $this->withoutVite();
    $reference = staffRequestFixture();
    $id = DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
    DB::table('request_audit_logs')->insert([
        'id' => (string) Str::uuid(), 'funeral_request_id' => $id, 'actor_user_id' => null,
        'action' => 'test_rollback', 'after' => json_encode(['source' => 'manual_test_recovery']),
        'occurred_at' => now()->utc()->addSecond(),
    ]);
    $staff = User::factory()->create(['role' => 'admin', 'active' => true]);
    $this->post('/masuk', ['phone' => $staff->phone, 'password' => 'password']);
    $this->get('/staf/pengajuan/'.$id)->assertInertia(fn (Assert $page) => $page
        ->where('activity.data.0.action', 'test_rollback')
        ->where('activity.data.0.actor', 'Pemulihan manual atas permintaan pengguna')
        ->where('activity.data.1.actor', 'Pemohon'));
});

test('activity loads ten entries first and returns older entries with a merge cursor', function () {
    $this->withoutVite();
    $reference = staffRequestFixture();
    $id = DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
    $staff = User::factory()->create(['role' => 'admin', 'active' => true]);
    $this->post('/masuk', ['phone' => $staff->phone, 'password' => 'password']);
    $start = now()->utc();
    for ($i = 1; $i <= 10; $i++) {
        DB::table('request_audit_logs')->insert([
            'id' => (string) Str::uuid(), 'funeral_request_id' => $id, 'actor_user_id' => $staff->id,
            'action' => 'edited', 'occurred_at' => $start->copy()->addSeconds($i),
        ]);
    }
    $url = '/staf/pengajuan/'.$id;
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create($url)) ?? ''];
    $response = $this->get($url, $headers)->assertOk()->assertJsonCount(10, 'props.activity.data');
    $cursor = $response->json('props.activity.next_cursor');
    expect($cursor)->toBeString();
    $this->get($url.'?'.http_build_query(['activity_cursor' => $cursor]), $headers + [
        'X-Inertia-Partial-Component' => 'staff-request', 'X-Inertia-Partial-Data' => 'activity',
    ])->assertOk()->assertJsonCount(1, 'props.activity.data')
        ->assertJsonPath('props.activity.data.0.actor', 'Pemohon')
        ->assertJsonPath('mergeProps.0', 'activity.data');
});
