<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['pic', 'manager', 'admin'] as $role) {
        User::factory()->create(['role' => $role, 'active' => true]);
    }
    config(['funeral.turnstile.secret' => 'test-secret', 'funeral.turnstile.hostname' => 'localhost']);
    Http::preventStrayRequests();
});

function publicPayload(): array
{
    return [
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Keluarga', 'deceased_name' => 'Nama Uji',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Lokasi Uji',
        'has_plot' => '0', 'estimated_burial_date' => now('Asia/Jakarta')->addDays(2)->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => '1', 'turnstile_token' => 'test-token',
    ];
}

test('the API replays a committed submission without consuming another token', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'funeral_submit'])]);
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $payload = publicPayload();
    $payload['funeral_home_room'] = 'Anggrek 2';
    $url = '/api/v1/funeral-requests';
    $receipt = $this->postJson($url, $payload, $headers)->assertCreated()->json();
    unset($payload['turnstile_token']);
    $this->postJson($url, $payload, $headers)->assertCreated()->assertExactJson($receipt);
    $this->postJson($url, ['applicant_name' => 'Berbeda'] + $payload, $headers)->assertConflict();
    $this->postJson($url, ['funeral_home_room' => 'Melati 1'] + $payload, $headers)->assertConflict();
    Http::assertSentCount(1);
    $this->assertDatabaseCount('funeral_requests', 1)->assertDatabaseCount('notification_deliveries', 4);
    $this->assertDatabaseHas('funeral_requests', ['funeral_home_room' => 'Anggrek 2']);
});

test('the funeral home room is optional and accepted through the web form', function (?string $room) {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'funeral_submit'])]);
    $this->post('/pengajuan', publicPayload() + [
        'funeral_home_room' => $room, 'submission_key' => (string) Str::uuid(),
    ])->assertRedirect('/')->assertSessionHas('funeral_receipt');
    $this->assertDatabaseHas('funeral_requests', ['funeral_home_room' => filled($room) ? trim($room) : null]);
})->with([null, '', '  Anggrek 2  ']);

test('invalid funeral home rooms are rejected before any external call', function (mixed $room) {
    $this->postJson('/api/v1/funeral-requests', publicPayload() + ['funeral_home_room' => $room], [
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('funeral_home_room');
    Http::assertNothingSent();
    $this->assertDatabaseCount('funeral_requests', 0);
})->with([[123], [['room' => 'Anggrek']], [str_repeat('a', 256)]]);

test('both public transports reject an invalid challenge', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'other.example', 'action' => 'funeral_submit'])]);
    $key = (string) Str::uuid();
    $this->postJson('/api/v1/funeral-requests', publicPayload(), ['Idempotency-Key' => $key])->assertUnprocessable();
    $this->from('/')->post('/pengajuan', publicPayload() + ['submission_key' => $key])->assertSessionHasErrors('turnstile_token');
    $this->assertDatabaseCount('funeral_requests', 0);
});

test('web multipart submission stores files only after verification and shares the API receipt', function () {
    Storage::fake('local');
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
        ->push(['success' => false])
        ->push(['success' => true, 'hostname' => 'localhost', 'action' => 'funeral_submit'])]);
    $key = (string) Str::uuid();
    $photo = fn () => UploadedFile::fake()->createWithContent('dokumen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzQAAAAASUVORK5CYII='));
    $payload = fn () => publicPayload() + ['documents' => ['deceased_ktp' => $photo()]];
    $this->from('/')->post('/pengajuan', $payload() + ['submission_key' => $key])->assertSessionHasErrors('turnstile_token');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $response = $this->post('/pengajuan', $payload() + ['submission_key' => $key])->assertRedirect('/')->assertSessionHas('funeral_receipt');
    $receipt = session('funeral_receipt');
    $this->post('/api/v1/funeral-requests', $payload(), ['Idempotency-Key' => $key])->assertCreated()->assertExactJson($receipt);
    Http::assertSentCount(2);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('funeral_requests', 1);
});

test('a filled honeypot and a failed verification service never store a request', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response([], 503)]);
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $url = '/api/v1/funeral-requests';
    $this->postJson($url, publicPayload() + ['website' => 'bot'], $headers)->assertUnprocessable();
    Http::assertNothingSent();
    $this->postJson($url, publicPayload(), $headers)->assertServiceUnavailable();
    $this->assertDatabaseCount('funeral_requests', 0);
});

test('PDFs stay out of durable storage if the scanner is unavailable', function () {
    Storage::fake('local');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'funeral_submit'])]);
    config(['funeral.pdf_scanner' => null]);
    $pdf = UploadedFile::fake()->createWithContent('surat.pdf', "%PDF-1.4\n%%EOF\n");
    $this->post('/api/v1/funeral-requests', publicPayload() + ['documents' => ['hospital_death_certificate' => $pdf]], [
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertServiceUnavailable();
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->assertDatabaseCount('funeral_requests', 0);
});

test('PDF scanning uses streaming for clamdscan and preserves the standalone scanner', function (string $scanner, array $options) {
    Storage::fake('local');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'funeral_submit'])]);
    Process::fake();
    config(['funeral.pdf_scanner' => $scanner]);
    $pdf = UploadedFile::fake()->createWithContent('surat.pdf', "%PDF-1.4\n%%EOF\n");

    $this->post('/api/v1/funeral-requests', publicPayload() + ['documents' => ['hospital_death_certificate' => $pdf]], [
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    Process::assertRan(fn ($process) => $process->command === [
        $scanner, '--no-summary', ...$options, $pdf->getPathname(),
    ]);
})->with([
    'daemon' => ['/usr/bin/clamdscan', ['--stream']],
    'standalone' => ['/usr/bin/clamscan', []],
]);
