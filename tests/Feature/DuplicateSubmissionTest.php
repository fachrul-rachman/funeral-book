<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    config(['funeral.turnstile.secret' => 'test-secret', 'funeral.turnstile.hostname' => 'localhost']);
    Http::fake(['challenges.cloudflare.com/*' => fn ($request) => Http::response([
        'success' => $request['response'] !== 'invalid-token', 'hostname' => 'localhost', 'action' => 'funeral_submit',
    ])]);
    $this->payload = [
        'applicant_name' => 'Pemohon Uji', 'applicant_whatsapp' => '081234567890',
        'relationship_to_deceased' => 'Anak', 'deceased_name' => 'Almarhum Uji Rahasia',
        'deceased_date_of_death' => '2025-01-01', 'funeral_home_location' => 'Rumah Duka Uji',
        'has_plot' => false, 'estimated_burial_date' => now('Asia/Jakarta')->addDay()->toDateString(),
        'estimated_burial_time' => '10:00', 'consent_accepted' => true, 'turnstile_token' => 'test-token',
    ];
});

test('normalized phone and death date warn without exposing the previous request or storing another submission', function (string $phone) {
    $reference = $this->postJson('/api/v1/funeral-requests', $this->payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->json('reference_code');
    $payload = ['applicant_whatsapp' => $phone, 'deceased_name' => 'Nama disingkat'] + $this->payload;
    $this->postJson('/api/v1/funeral-requests', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('different_deceased_confirmed')
        ->assertDontSee($reference)->assertDontSee('Almarhum Uji Rahasia');
    $this->assertDatabaseCount('funeral_requests', 1)->assertDatabaseCount('notification_deliveries', 1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['081234567890', '6281234567890', '+62 812-3456-7890']);

test('explicit different-person confirmation permits a new request and replays it without another intent', function () {
    $url = '/api/v1/funeral-requests';
    $this->postJson($url, $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson($url, $this->payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('different_deceased_confirmed');
    $payload = ['different_deceased_confirmed' => true, 'deceased_name' => 'Almarhum Uji Kedua'] + $this->payload;
    $receipt = $this->postJson($url, $payload, $headers)->assertCreated()->json();
    unset($payload['turnstile_token']);
    $this->postJson($url, $payload, $headers)->assertCreated()->assertExactJson($receipt);
    $this->assertDatabaseCount('funeral_requests', 2)->assertDatabaseCount('notification_deliveries', 2);
    Http::assertSentCount(3);
});

test('a different customer phone or death date does not require confirmation', function (array $difference) {
    $url = '/api/v1/funeral-requests';
    $this->postJson($url, $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->postJson($url, $difference + $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->assertDatabaseCount('funeral_requests', 2);
})->with([[['applicant_whatsapp' => '081234567891']], [['deceased_date_of_death' => '2025-01-02']]]);

test('web multipart duplicates are rejected before documents are stored', function () {
    $this->postJson('/api/v1/funeral-requests', $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $photo = UploadedFile::fake()->createWithContent('dokumen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzQAAAAASUVORK5CYII='));
    $this->from('/')->post('/pengajuan', $this->payload + [
        'submission_key' => (string) Str::uuid(), 'documents' => ['deceased_ktp' => $photo],
    ])->assertRedirect('/')->assertSessionHasErrors('different_deceased_confirmed');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->assertDatabaseCount('request_documents', 0);
});

test('invalid confirmation is rejected before calling the verification provider', function () {
    Http::fake();
    $this->postJson('/api/v1/funeral-requests', $this->payload + ['different_deceased_confirmed' => 'yes'], [
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('different_deceased_confirmed');
    Http::assertNothingSent();
});

test('repeated duplicate warnings do not consume the quota needed to confirm a different person', function () {
    $url = '/api/v1/funeral-requests';
    $this->postJson($url, $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->postJson($url, $this->payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('different_deceased_confirmed');
    }
    $this->postJson($url, ['different_deceased_confirmed' => true] + $this->payload, [
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();
    $this->assertDatabaseCount('funeral_requests', 2);
});

test('a failed security challenge cannot be used to discover a duplicate', function () {
    $this->postJson('/api/v1/funeral-requests', $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->postJson('/api/v1/funeral-requests', ['turnstile_token' => 'invalid-token'] + $this->payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token')->assertJsonMissingPath('errors.different_deceased_confirmed');
    $this->assertDatabaseCount('funeral_requests', 1);
});

test('cancelled requests are excluded but completed requests still trigger the warning', function (string $status, int $responseStatus) {
    $url = '/api/v1/funeral-requests';
    $this->postJson($url, $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    DB::table('funeral_requests')->update(['status' => $status, 'cancellation_reason' => $status === 'cancelled' ? 'Pengajuan uji dibatalkan' : null]);
    $response = $this->postJson($url, $this->payload, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus($responseStatus);
    if ($status === 'completed') {
        $response->assertJsonValidationErrors('different_deceased_confirmed');
    }
})->with([['cancelled', 201], ['completed', 422]]);
