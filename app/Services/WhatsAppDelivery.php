<?php

namespace App\Services;

use App\Jobs\SendWhatsAppDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class WhatsAppDelivery
{
    private const MAX_ATTEMPTS = 3;

    private const STATUS_ORDER = ['pending' => 0, 'processing' => 0, 'unknown' => 0, 'accepted' => 1, 'sent' => 2, 'failed' => 3, 'delivered' => 4, 'read' => 5];

    public static function mayRetry(User $actor, stdClass $request, stdClass $delivery): bool
    {
        return $actor->active && (in_array($actor->role, ['manager', 'superadmin'], true)
            || ($actor->role === 'pic' && ($delivery->event_type === 'completed'
                ? $request->responsible_user_id === $actor->id
                : $delivery->destination_e164 === $actor->phone)));
    }

    public function retryNotification(User $actor, string $requestId, string $deliveryId, bool $acknowledgeUncertain = false): void
    {
        DB::transaction(function () use ($actor, $requestId, $deliveryId, $acknowledgeUncertain): void {
            $currentActor = User::query()->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($currentActor && $currentActor->active && $currentActor->access_version === $actor->access_version, 403);
            $request = DB::table('funeral_requests')->where('id', $requestId)->lockForUpdate()->first();
            $delivery = DB::table('notification_deliveries')->where('id', $deliveryId)->where('funeral_request_id', $requestId)->lockForUpdate()->first();
            abort_if($request === null || $delivery === null, 404);
            abort_unless(self::mayRetry($currentActor, $request, $delivery), 403);
            abort_if(! in_array($delivery->state, ['failed', 'unknown'], true) || $delivery->superseded_by !== null
                || $delivery->provider_message_id !== null, 409, 'Pesan sudah diproses atau dijadwalkan ulang.');
            if ($request->status === 'cancelled') {
                throw ValidationException::withMessages(['retry' => 'Pengajuan sudah dibatalkan.']);
            }
            if ($delivery->state === 'unknown' && ! $acknowledgeUncertain) {
                throw ValidationException::withMessages(['retry' => 'Konfirmasikan risiko pesan ganda sebelum mengirim ulang.']);
            }
            if (! config('funeral.whatsapp.enabled')) {
                throw ValidationException::withMessages(['retry' => 'Pengiriman WhatsApp belum diaktifkan.']);
            }
            if ($delivery->event_type === 'completed' && ($request->status !== 'completed'
                || ! DB::table('visitation_plan_requests')->where('funeral_request_id', $requestId)->where('state', 'ready')->where('expires_at', '>', now()->utc())->exists())) {
                throw ValidationException::withMessages(['retry' => 'Tautan ziarah belum tersedia atau sudah kedaluwarsa.']);
            }
            $id = (string) Str::uuid();
            $now = now()->utc();
            DB::table('notification_deliveries')->insert([
                'id' => $id, 'funeral_request_id' => $requestId, 'event_type' => $delivery->event_type,
                'recipient_role' => $delivery->recipient_role, 'destination_e164' => $delivery->destination_e164,
                'template_name' => config('funeral.templates.'.$delivery->event_type),
                'template_parameters' => $delivery->event_type === 'new_request' ? $delivery->template_parameters : null,
                'idempotency_key' => 'retry:'.$deliveryId.':'.$id, 'state' => 'pending',
                'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('notification_deliveries')->where('id', $deliveryId)->update(['superseded_by' => $id, 'updated_at' => $now]);
            DB::table('request_audit_logs')->insert([
                'id' => (string) Str::uuid(), 'funeral_request_id' => $requestId, 'actor_user_id' => $currentActor->id,
                'action' => 'notification_retry_requested',
                'before' => json_encode(['delivery_id' => $deliveryId, 'state' => $delivery->state, 'error_code' => $delivery->last_error_code], JSON_THROW_ON_ERROR),
                'after' => json_encode(['delivery_id' => $id, 'event_type' => $delivery->event_type, 'duplicate_risk_acknowledged' => $acknowledgeUncertain], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);
            DB::afterCommit(fn () => $this->dispatch($id));
        });
    }

    public function dispatch(string $id): void
    {
        try {
            $due = $this->due();
            if ($due !== null && $due->where('id', $id)->exists()) {
                SendWhatsAppDelivery::dispatch($id)->onConnection('database')->onQueue('whatsapp');
            }
        } catch (Throwable) {
            logger()->warning('WhatsApp queue dispatch interrupted; the outbox will retry dispatch.');
        }
    }

    public function recover(): void
    {
        $now = now()->utc();
        DB::table('notification_deliveries')->where('state', 'processing')
            ->where(fn (Builder $q) => $q->whereNull('processing_started_at')->orWhere('processing_started_at', '<=', $now->copy()->subMinutes(2)))
            ->update([
                'state' => 'unknown', 'next_attempt_at' => null, 'last_error_code' => 'processing_expired',
                'last_error_message_redacted' => 'Hasil pengiriman belum diketahui.', 'updated_at' => $now,
            ]);

        $due = $this->due();
        if ($due === null) {
            return;
        }
        foreach ($due->orderBy('next_attempt_at')->orderBy('created_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
            $this->dispatch($id);
        }
    }

    public function send(string $id): void
    {
        $due = $this->due();
        if ($due === null) {
            return;
        }

        $row = DB::transaction(function () use ($id, $due) {
            $row = $due->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $row->attempt_count++;
            $now = now()->utc();
            DB::table('notification_deliveries')->where('id', $id)->update([
                'state' => 'processing', 'attempt_count' => $row->attempt_count,
                'processing_started_at' => $now, 'next_attempt_at' => null, 'updated_at' => $now,
            ]);

            return $row;
        });
        if ($row === null) {
            return;
        }

        $request = DB::table('funeral_requests')->where('id', $row->funeral_request_id)->first();
        if ($request === null) {
            $this->finish($id, 'failed', 'request_missing');

            return;
        }
        $buttonSuffix = 'pengajuan/'.$row->funeral_request_id;
        if ($row->template_parameters !== null && $row->event_type === 'new_request') {
            $parameters = json_decode($row->template_parameters, true, 512, JSON_THROW_ON_ERROR);
        } elseif ($row->event_type === 'new_request') {
            $parameters = self::newRequestParameters($request);
        } else {
            $plan = DB::table('visitation_plan_requests')->where('funeral_request_id', $request->id)
                ->where('state', 'ready')->where('expires_at', '>', now()->utc())->first();
            if ($plan === null) {
                $this->finish($id, 'pending', 'visitation_not_ready', 60);

                return;
            }
            $path = parse_url($plan->url, PHP_URL_PATH);
            if (! is_string($path) || trim($path, '/') === '') {
                $this->finish($id, 'failed', 'visitation_url_invalid');

                return;
            }
            $buttonSuffix = ltrim($path, '/');
            $query = parse_url($plan->url, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $buttonSuffix .= '?'.$query;
            }
            $parameters = [Str::squish($request->deceased_name)];
        }
        DB::table('notification_deliveries')->where('id', $id)->update([
            'template_parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
        ]);
        $template = [
            'name' => $row->template_name,
            'language' => ['code' => config("funeral.whatsapp.languages.$row->event_type")],
            'components' => [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters),
            ]],
        ];
        $template['components'][] = [
            'type' => 'button', 'sub_type' => 'url', 'index' => '0',
            'parameters' => [['type' => 'text', 'text' => $buttonSuffix]],
        ];

        $version = config('funeral.whatsapp.version');
        $phoneId = config('funeral.whatsapp.phone_id');
        try {
            $response = Http::withToken((string) config('funeral.whatsapp.access_token'))->acceptJson()
                ->connectTimeout(4)->timeout(12)->withoutRedirecting()
                ->post("https://graph.facebook.com/$version/$phoneId/messages", [
                    'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                    'to' => ltrim($row->destination_e164, '+'), 'type' => 'template',
                    'template' => $template, 'biz_opaque_callback_data' => $id,
                ]);
        } catch (ConnectionException) {
            $this->finish($id, 'unknown', 'connection_uncertain');

            return;
        }

        $providerId = $response->json('messages.0.id');
        if ($response->successful() && is_string($providerId) && $providerId !== '' && strlen($providerId) <= 255) {
            DB::table('notification_deliveries')->where('id', $id)->whereIn('state', ['processing', 'unknown'])
                ->whereNull('provider_message_id')->update([
                    'state' => 'accepted', 'provider_message_id' => $providerId,
                    'last_error_code' => null, 'last_error_message_redacted' => null, 'updated_at' => now()->utc(),
                ]);

            return;
        }

        $code = $response->json('error.code');
        $errorCode = is_int($code) ? (string) $code : 'http_'.$response->status();
        $transient = $response->tooManyRequests()
            || ($response->clientError() && $response->status() !== 408 && is_int($code) && $response->json('error.is_transient') === true);
        if ($transient && $row->attempt_count < self::MAX_ATTEMPTS) {
            $retryAfter = $response->header('Retry-After');
            $minimum = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: 0) - now()->getTimestamp());
            $delay = max($minimum, 30 * (2 ** ($row->attempt_count - 1)) + random_int(0, 15));
            $this->finish($id, 'pending', $errorCode, $delay);
        } else {
            $definite = $transient || ($response->clientError() && $response->status() !== 408);
            $this->finish($id, $definite ? 'failed' : 'unknown', $errorCode);
        }
    }

    public function reconcile(string $providerId, string $state, ?string $correlation, ?int $code): void
    {
        if (! in_array($state, ['sent', 'delivered', 'read', 'failed'], true) || $providerId === '' || strlen($providerId) > 255) {
            return;
        }

        DB::transaction(function () use ($providerId, $state, $correlation, $code) {
            $row = null;
            if ($correlation !== null && Str::isUuid($correlation)) {
                $row = DB::table('notification_deliveries')->where('id', $correlation)
                    ->where(fn (Builder $q) => $q->where('provider_message_id', $providerId)->orWhere(
                        fn (Builder $pending) => $pending->whereNull('provider_message_id')->where('attempt_count', '>', 0)->whereIn('state', ['processing', 'unknown'])
                    ))->lockForUpdate()->first();
            }
            $row ??= DB::table('notification_deliveries')->where('provider_message_id', $providerId)->lockForUpdate()->first();
            if ($row === null || self::STATUS_ORDER[$state] <= self::STATUS_ORDER[$row->state]) {
                return;
            }

            DB::table('notification_deliveries')->where('id', $row->id)->update([
                'state' => $state, 'provider_message_id' => $providerId, 'next_attempt_at' => null,
                'last_error_code' => $state === 'failed' ? ($code === null ? 'webhook_failed' : (string) $code) : null,
                'last_error_message_redacted' => $state === 'failed' ? 'Meta melaporkan pesan gagal terkirim.' : null,
                'updated_at' => now()->utc(),
            ]);
        });
    }

    private function due(): ?Builder
    {
        $version = config('funeral.whatsapp.version');
        $phoneId = config('funeral.whatsapp.phone_id');
        $token = config('funeral.whatsapp.access_token');
        if (! config('funeral.whatsapp.enabled') || ! is_string($version) || ! preg_match('/^v\d+\.\d+$/D', $version)
            || ! is_string($phoneId) || ! ctype_digit($phoneId) || ! is_string($token) || $token === '') {
            return null;
        }

        $configuredRecipient = config('funeral.whatsapp.test_recipient');
        $testRecipient = User::normalizePhone($configuredRecipient);
        if (filled($configuredRecipient) && $testRecipient === null) {
            return null;
        }

        $events = array_filter(['new_request', 'completed'], fn (string $event) => filled(config("funeral.whatsapp.languages.$event")));
        if ($events === []) {
            return null;
        }

        $due = DB::table('notification_deliveries')->where('state', 'pending')->whereNull('superseded_by')->whereIn('event_type', $events)
            ->where('attempt_count', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()->utc()));

        $due->where(fn (Builder $q) => $q->where('event_type', 'new_request')->orWhere(
            fn (Builder $completed) => $completed->where('event_type', 'completed')->whereExists(
                fn (Builder $plan) => $plan->selectRaw('1')->from('visitation_plan_requests')
                    ->whereColumn('visitation_plan_requests.funeral_request_id', 'notification_deliveries.funeral_request_id')
                    ->where('visitation_plan_requests.state', 'ready')->where('visitation_plan_requests.expires_at', '>', now()->utc())
            )
        ));

        return $testRecipient === null ? $due : $due->where('destination_e164', $testRecipient);
    }

    /** @return list<string> */
    public static function newRequestParameters(stdClass $request): array
    {
        $schedule = CarbonImmutable::parse($request->current_burial_at, 'UTC')->setTimezone('Asia/Jakarta');
        $site = match ($request->burial_site) {
            'karawang' => 'Karawang',
            'tangerang' => 'Tangerang',
            default => 'Belum diisi',
        };
        $values = [
            $request->reference_code,
            $request->applicant_name,
            $request->applicant_whatsapp_e164,
            $request->relationship_to_deceased,
            $request->deceased_name,
            CarbonImmutable::parse($request->deceased_date_of_death)->settings(['locale' => 'id'])->translatedFormat('d M Y'),
            $request->funeral_home_location,
            $site,
            $schedule->settings(['locale' => 'id'])->translatedFormat('d M Y'),
            $schedule->format('H.i'),
            $request->has_plot ? 'Sudah memiliki lahan' : 'Belum memiliki lahan',
            $request->lot_number,
        ];

        return array_map(fn (?string $value): string => filled($value)
            ? trim(preg_replace('/\s+/u', ' ', $value) ?? $value) : 'Belum diisi', $values);
    }

    private function finish(string $id, string $state, string $code, ?int $delay = null): void
    {
        $now = now()->utc();
        DB::table('notification_deliveries')->where('id', $id)->where('state', 'processing')->update([
            'state' => $state, 'next_attempt_at' => $delay === null ? null : $now->copy()->addSeconds($delay),
            'last_error_code' => $code,
            'last_error_message_redacted' => match ($state) {
                'pending' => 'Meta menolak sementara. Pengiriman akan dicoba lagi.',
                'failed' => 'Pesan gagal dikirim.',
                default => 'Hasil pengiriman belum diketahui.',
            },
            'updated_at' => $now,
        ]);
    }
}
