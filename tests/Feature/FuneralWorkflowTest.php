<?php

use App\Models\User;
use App\Services\FuneralRequests;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    foreach (['pic', 'manager', 'admin'] as $role) {
        User::factory()->create(['role' => $role, 'active' => true]);
    }
});

function funeralInput(array $overrides = []): array
{
    return $overrides + [
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Keluarga', 'deceased_name' => 'Nama Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Lokasi Uji',
        'has_plot' => false, 'estimated_burial_date' => now('Asia/Jakarta')->addDays(2)->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => true,
    ];
}

function submitFuneral(): string
{
    $reference = app(FuneralRequests::class)->submit(funeralInput());

    return DB::table('funeral_requests')->where('reference_code', $reference)->value('id');
}

function contactPhoto(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzQAAAAASUVORK5CYII='));
}

test('claim and advance reject stale statuses without duplicate evidence or intents', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $other = User::factory()->create(['active' => true]);
    expect(fn () => $service->transition($pic, $id, 'new_request', 'contacted'))->toThrow(ValidationException::class);
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    expect(fn () => $service->transition($other, $id, 'new_request', 'contacted', contactPhoto()))
        ->toThrow(HttpException::class, 'Status pengajuan telah berubah.');
    $service->transition($pic, $id, 'contacted', 'in_progress');
    expect(fn () => $service->transition($pic, $id, 'contacted', 'in_progress'))
        ->toThrow(HttpException::class, 'Status pengajuan telah berubah.');
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'in_progress', 'responsible_user_id' => $pic->id]);
    expect(DB::table('request_documents')->count())->toBe(1);
    expect(DB::table('notification_deliveries')->count())->toBe(3);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

test('completion returns blockers and records one intent before making the request immutable', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    $service->transition($pic, $id, 'contacted', 'in_progress');
    try {
        $service->transition($pic, $id, 'in_progress', 'completed');
        $this->fail('Completion should be blocked.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['has_plot', 'zone', 'paid_in_full', 'deceased_ktp']);
    }
    $service->edit($pic, $id, ['has_plot' => true, 'burial_site' => 'karawang', 'zone' => 'A', 'lot_number' => 'UJI', 'paid_in_full' => true]);
    foreach (['plot_owner_ktp', 'deceased_ktp', 'hospital_death_certificate', 'ownership_certificate'] as $type) {
        $service->attach($pic, $id, $type, contactPhoto());
    }
    $service->attach($pic, $id, 'deceased_ktp', UploadedFile::fake()->createWithContent('pending.pdf', "%PDF-1.4\n"));
    $this->assertDatabaseHas('request_documents', ['type' => 'deceased_ktp', 'validation_state' => 'accepted', 'superseded_at' => null]);
    $this->assertDatabaseHas('request_documents', ['type' => 'deceased_ktp', 'validation_state' => 'pending']);
    $service->transition($pic, $id, 'in_progress', 'completed');
    expect(DB::table('notification_deliveries')->where('event_type', 'completed')->count())->toBe(1);
    expect(fn () => $service->edit($pic, $id, ['lot_number' => 'UJI-2']))->toThrow(HttpException::class, 'Pengajuan sudah final.');
});

test('account eligibility protects an assigned request until a manager transfers it', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $owner = User::factory()->create(['active' => true, 'role' => 'superadmin']);
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    $this->post('/masuk', ['phone' => $owner->phone, 'password' => 'password'])->assertRedirect('/staf');
    $payload = ['role' => 'pic', 'active' => false];
    $this->patch('/staf/pengguna/'.$pic->id, $payload)->assertSessionHasErrors('active');
    $service->transfer($owner, $id, $owner->id);
    expect(fn () => $service->edit($pic, $id, ['lot_number' => 'UJI']))->toThrow(HttpException::class);
    $this->patch('/staf/pengguna/'.$pic->id, $payload)->assertSessionHasNoErrors();
    expect($pic->refresh()->active)->toBeFalse();
    $service->transition($owner, $id, 'contacted', 'cancelled', reason: 'Alasan uji');
    expect(DB::table('notification_deliveries')->where('event_type', 'completed')->count())->toBe(0);
});

test('saving the schedule updates it immediately and preserves the initial estimate', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $initial = DB::table('funeral_requests')->where('id', $id)->value('applicant_estimated_burial_at');
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    $service->edit($pic, $id, ['current_burial_at' => '2028-01-01T12:00:00+07:00']);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'applicant_estimated_burial_at' => $initial, 'current_burial_at' => '2028-01-01 05:00:00']);
});

test('first response counts only operating windows including weekends', function () {
    $friday = CarbonImmutable::parse('2026-11-06 16:30', 'Asia/Jakarta');
    $sunday = CarbonImmutable::parse('2026-11-08 08:30', 'Asia/Jakarta');
    expect(FuneralRequests::responseSeconds($friday, $sunday))->toBe(10 * 3600);
});

test('submission failure rolls back the request and outbox and cleans staged files', function () {
    $service = app(FuneralRequests::class);
    $replay = ['digest' => str_repeat('a', 64), 'fingerprint' => str_repeat('b', 64)];
    $service->submit(funeralInput(), replay: $replay);
    expect(fn () => $service->submit(funeralInput(), ['deceased_ktp' => contactPhoto()], $replay))
        ->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('funeral_requests', 1)->assertDatabaseCount('notification_deliveries', 3);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('only current accepted documents can be opened and access is audited', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    $service->attach($pic, $id, 'deceased_ktp', contactPhoto());
    $documentId = DB::table('request_documents')->where('type', 'deceased_ktp')->value('id');
    $download = $service->openDocument($admin, $id, $documentId);
    fclose($download['stream']);
    $this->assertDatabaseHas('request_audit_logs', ['actor_user_id' => $admin->id, 'action' => 'document_accessed']);
    expect(fn () => $service->attach($admin, $id, 'deceased_ktp', contactPhoto()))->toThrow(HttpException::class);
    $service->attach($pic, $id, 'deceased_ktp', contactPhoto());
    expect(fn () => $service->openDocument($admin, $id, $documentId))->toThrow(HttpException::class);
});

test('a new request records one intent for each active eligible staff account', function () {
    $secondPic = User::factory()->create(['role' => 'pic', 'active' => true]);
    $inactive = User::factory()->create(['role' => 'pic', 'active' => false]);
    $superadmin = User::factory()->create(['role' => 'superadmin', 'active' => true]);

    submitFuneral();

    $this->assertDatabaseCount('notification_deliveries', 4);
    $this->assertDatabaseHas('notification_deliveries', ['destination_e164' => $secondPic->phone, 'recipient_role' => 'pic']);
    $this->assertDatabaseMissing('notification_deliveries', ['destination_e164' => $inactive->phone]);
    $this->assertDatabaseMissing('notification_deliveries', ['destination_e164' => $superadmin->phone]);
});

test('submission still succeeds when no eligible staff account is active', function () {
    User::query()->update(['active' => false]);

    submitFuneral();

    $this->assertDatabaseCount('funeral_requests', 1)->assertDatabaseCount('notification_deliveries', 0);
});

test('applicant phone inputs match the visitation contract before normalization', function (string $input, string $expected) {
    $data = app(FuneralRequests::class)->submissionFields(funeralInput(['applicant_whatsapp' => $input]));
    expect($data['applicant_whatsapp_e164'])->toBe($expected);
})->with([
    ['081234567890', '+6281234567890'], ['6281234567890', '+6281234567890'],
    ['+62 (812) 3456-7890', '+6281234567890'], ['0812345678901', '+62812345678901'],
    ['6212345678', '+6212345678'],
]);

test('public input rejects data that the visitation API cannot accept', function (array $overrides, string $field) {
    try {
        app(FuneralRequests::class)->submit(funeralInput($overrides));
        $this->fail('Invalid integration data must be rejected before submission.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('funeral_requests', 0);
})->with([
    [['applicant_whatsapp' => '+14155552671'], 'applicant_whatsapp'],
    [['applicant_whatsapp' => '081234567'], 'applicant_whatsapp'],
    [['applicant_whatsapp' => '62812345678901'], 'applicant_whatsapp'],
    [['relationship_to_deceased' => str_repeat('X', 101)], 'relationship_to_deceased'],
    [['deceased_date_of_death' => '2030-01-02', 'estimated_burial_date' => '2030-01-01'], 'estimated_burial_date'],
]);

test('staff edits enforce chronology in WIB including midnight across UTC dates', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    expect(fn () => $service->edit($pic, $id, ['deceased_date_of_death' => '2030-01-01']))->toThrow(ValidationException::class);
    expect(fn () => $service->edit($pic, $id, ['current_burial_at' => '2024-01-01T12:00:00+07:00']))->toThrow(ValidationException::class);
    $service->edit($pic, $id, ['deceased_date_of_death' => '2028-01-01', 'current_burial_at' => '2028-01-01T00:30:00+07:00']);
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'deceased_date_of_death' => '2028-01-01', 'current_burial_at' => '2027-12-31 17:30:00']);
});

test('legacy invalid integration fields block completion before the request becomes final', function () {
    $service = app(FuneralRequests::class);
    $id = submitFuneral();
    $pic = User::factory()->create(['active' => true]);
    $service->transition($pic, $id, 'new_request', 'contacted', contactPhoto());
    $service->transition($pic, $id, 'contacted', 'in_progress');
    DB::table('funeral_requests')->where('id', $id)->update([
        'applicant_whatsapp_e164' => '+14155552671', 'relationship_to_deceased' => str_repeat('X', 101),
        'deceased_date_of_death' => '2030-01-01',
    ]);
    try {
        $service->transition($pic, $id, 'in_progress', 'completed');
        $this->fail('Legacy invalid data must not reach the integration.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKeys(['applicant_whatsapp', 'relationship_to_deceased', 'current_burial_at']);
    }
    $this->assertDatabaseHas('funeral_requests', ['id' => $id, 'status' => 'in_progress']);
    $this->assertDatabaseMissing('notification_deliveries', ['funeral_request_id' => $id, 'event_type' => 'completed']);
});
