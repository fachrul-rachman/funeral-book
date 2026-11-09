<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublicFuneralSubmission
{
    public function __construct(private FuneralRequests $requests) {}

    /** @return array{reference_code: string, message: string} */
    public function submit(Request $request): array
    {
        abort_if((int) $request->server('CONTENT_LENGTH') > 27 * 1024 * 1024, 413);
        $envelope = Validator::make([
            'key' => $request->is('api/*') ? $request->header('Idempotency-Key') : $request->input('submission_key'),
            'website' => $request->input('website'),
            'documents' => $request->file('documents', []),
        ], [
            'key' => ['required', 'uuid'],
            'website' => ['prohibited'],
            'documents' => ['array:ownership_certificate,plot_owner_ktp,deceased_ktp,hospital_death_certificate,embassy_death_certificate', 'max:5'],
            'documents.*' => ['required', 'file', 'max:5120'],
        ])->validate();
        if ($request->has('documents') && ! is_array($request->input('documents', []))) {
            throw ValidationException::withMessages(['documents' => 'Format dokumen tidak valid.']);
        }

        $fields = $this->requests->submissionFields($request->all());
        $files = $envelope['documents'];
        ksort($files);
        $descriptors = [];
        foreach ($files as $type => $file) {
            /** @var UploadedFile $file */
            $descriptors[$type] = [$file->getClientOriginalName(), hash_file('sha256', $file->getPathname())];
        }
        $key = hash('sha256', strtolower($envelope['key']));
        $fingerprint = hash('sha256', json_encode([$fields, $descriptors], JSON_THROW_ON_ERROR));
        $reference = $this->replay($key, $fingerprint);
        if ($reference === null) {
            $this->verifyTurnstile($request);
            $ip = $this->limiterKey('ip', (string) $request->ip());
            $phone = $this->limiterKey('phone', $fields['applicant_whatsapp_e164']);

            $reference = Cache::lock($ip.':lock', 10)->block(3, fn () => Cache::lock($phone.':lock', 10)->block(3, function () use ($ip, $phone, $key, $fingerprint) {
                $reference = $this->replay($key, $fingerprint);
                if ($reference === null) {
                    abort_if(RateLimiter::tooManyAttempts($ip, 3) || RateLimiter::tooManyAttempts($phone, 3), 429, 'Batas pengajuan tercapai. Coba lagi nanti.');
                    RateLimiter::hit($ip, 3600);
                    RateLimiter::hit($phone, 86400);
                }

                return $reference;
            }));
            if ($reference === null) {
                try {
                    $reference = $this->requests->submit($request->all(), $files, ['digest' => $key, 'fingerprint' => $fingerprint]);
                } catch (UniqueConstraintViolationException $error) {
                    $reference = $this->replay($key, $fingerprint) ?? throw $error;
                }
            }
        }

        return ['reference_code' => $reference, 'message' => 'Pengajuan diterima. Tim kami akan menghubungi Anda melalui WhatsApp.'];
    }

    private function limiterKey(string $type, string $value): string
    {
        return 'funeral-submit:'.$type.':'.hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function replay(string $key, string $fingerprint): ?string
    {
        $record = DB::table('public_submission_keys')->join('funeral_requests', 'funeral_requests.id', '=', 'public_submission_keys.funeral_request_id')
            ->where('key_digest', $key)->where('expires_at', '>', now()->utc())->first(['payload_fingerprint', 'reference_code']);
        if ($record === null) {
            return null;
        }

        abort_unless(hash_equals($record->payload_fingerprint, $fingerprint), 409, 'Isi pengajuan berbeda. Mulai pengiriman baru.');

        return $record->reference_code;
    }

    private function verifyTurnstile(Request $request): void
    {
        $token = $request->validate(['turnstile_token' => ['required', 'string', 'max:2048']])['turnstile_token'];
        $secret = config('funeral.turnstile.secret');
        $hostname = config('funeral.turnstile.hostname');
        abort_unless(is_string($secret) && $secret !== '' && is_string($hostname) && $hostname !== '', 503, 'Verifikasi belum tersedia.');

        try {
            $result = Http::asForm()->connectTimeout(3)->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret, 'response' => $token, 'remoteip' => $request->ip(),
            ]);
        } catch (ConnectionException) {
            abort(503, 'Verifikasi sedang tidak tersedia. Coba lagi.');
        }

        abort_unless($result->successful() && is_array($result->json()), 503, 'Verifikasi sedang tidak tersedia. Coba lagi.');
        if ($result->json('success') !== true || $result->json('hostname') !== $hostname || $result->json('action') !== 'funeral_submit') {
            throw ValidationException::withMessages(['turnstile_token' => 'Verifikasi tidak berhasil. Ulangi verifikasi.']);
        }
    }
}
