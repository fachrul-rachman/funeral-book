<?php

namespace App\Services;

use App\Jobs\CreateVisitationPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class VisitationPlans
{
    private const MAX_ATTEMPTS = 5;

    public function ensure(string $id): void
    {
        DB::transaction(function () use ($id) {
            $request = DB::table('funeral_requests')->where('id', $id)->where('status', 'completed')->lockForUpdate()->first();
            $existing = DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->lockForUpdate()->first();
            if ($request === null || ($existing !== null && $existing->validation_fields === null)) {
                return;
            }
            $now = now()->utc();
            $intent = [
                'funeral_request_id' => $id, 'payload' => json_encode($this->payload($request), JSON_THROW_ON_ERROR),
                'generation' => (string) Str::uuid(), 'validation_fields' => null,
                'state' => 'pending', 'attempt_count' => 0, 'processing_started_at' => null,
                'url' => null, 'expires_at' => null, 'last_error_code' => null,
                'next_attempt_at' => $now, 'updated_at' => $now,
            ];
            if ($existing === null) {
                DB::table('visitation_plan_requests')->insert($intent + ['created_at' => $now]);
            } else {
                DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->update($intent);
            }
            DB::afterCommit(fn () => $this->dispatch($id));
        });
    }

    public function dispatch(string $id): void
    {
        try {
            if ($this->configured() && $this->due()->where('funeral_request_id', $id)->exists()) {
                CreateVisitationPlan::dispatch($id)->onConnection('database')->onQueue('whatsapp');
            }
        } catch (Throwable) {
            logger()->warning('Visitation plan dispatch interrupted; the outbox will recover.');
        }
    }

    public function recover(): void
    {
        $now = now()->utc();
        DB::table('visitation_plan_requests')->where('state', 'ready')->where('expires_at', '<=', $now)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('notification_deliveries')
                ->whereColumn('notification_deliveries.funeral_request_id', 'visitation_plan_requests.funeral_request_id')
                ->where('event_type', 'completed')->where('state', 'pending'))
            ->update(['state' => 'failed', 'last_error_code' => 'visitation_link_expired', 'updated_at' => $now]);
        // Existing pending completion messages also acquire a durable API intent.
        foreach (DB::table('notification_deliveries as n')->leftJoin('visitation_plan_requests as v', 'v.funeral_request_id', '=', 'n.funeral_request_id')
            ->where('n.event_type', 'completed')->where('n.state', 'pending')->whereNull('v.funeral_request_id')
            ->select('n.funeral_request_id')->distinct()->limit(100)->get() as $row) {
            $this->ensure($row->funeral_request_id);
        }
        DB::table('visitation_plan_requests')->where('state', 'processing')
            ->where(fn (Builder $q) => $q->whereNull('processing_started_at')->orWhere('processing_started_at', '<=', $now->copy()->subMinutes(2)))
            ->update(['state' => 'pending', 'next_attempt_at' => $now, 'updated_at' => $now, 'last_error_code' => 'visitation_attempt_interrupted']);
        DB::table('visitation_plan_requests')->where('state', 'pending')->where('attempt_count', '>=', self::MAX_ATTEMPTS)
            ->update(['state' => 'failed', 'next_attempt_at' => null, 'updated_at' => $now, 'last_error_code' => 'visitation_retries_exhausted']);
        if (! $this->configured()) {
            return;
        }
        foreach ($this->due()->orderBy('next_attempt_at')->orderBy('funeral_request_id')->limit(100)->pluck('funeral_request_id') as $id) {
            $this->dispatch($id);
        }
    }

    public function create(string $id): void
    {
        if (! $this->configured()) {
            return;
        }
        $row = DB::transaction(function () use ($id) {
            $row = $this->due()->where('funeral_request_id', $id)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $row->attempt_count++;
            DB::table('visitation_plan_requests')->where('funeral_request_id', $id)->update([
                'state' => 'processing', 'attempt_count' => $row->attempt_count, 'processing_started_at' => now()->utc(),
                'next_attempt_at' => null, 'updated_at' => now()->utc(),
            ]);

            return $row;
        });
        if ($row === null) {
            return;
        }

        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $valid = Validator::make($payload, [
            'request_reference' => ['required', 'string'],
            'applicant.whatsapp' => ['required', 'string', 'regex:/^(?:08|62)[0-9]+$/D', 'min:10', 'max:13'],
            'burial.zone' => ['required', 'string'],
            'burial.lot_number' => ['required', 'string'],
        ]);
        if ($valid->fails()) {
            $this->finish($row, 'failed', 'visitation_payload_invalid');

            return;
        }

        try {
            $response = Http::withToken((string) config('funeral.visitation.key'))->acceptJson()->asJson()
                ->connectTimeout(4)->timeout(15)->withoutRedirecting()
                ->post((string) config('funeral.visitation.url'), $payload);
        } catch (ConnectionException) {
            $this->retry($row, 'visitation_connection_failed');

            return;
        }
        if (in_array($response->status(), [200, 201], true)) {
            $result = ['url' => $response->json('url'), 'expires_at' => $response->json('expires_at')];
            if (Validator::make($result, [
                'url' => ['required', 'string', 'url:https', 'max:4096'],
                'expires_at' => ['required', 'string', 'date', 'regex:/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D'],
            ])->fails() || parse_url((string) $result['url'], PHP_URL_USER) !== null) {
                $this->retry($row, 'visitation_response_invalid');

                return;
            }

            $expires = CarbonImmutable::parse($result['expires_at'])->utc();
            if ($expires->lte(now()->utc())) {
                $this->finish($row, 'failed', 'visitation_link_expired');

                return;
            }
            $saved = $this->finish($row, 'ready', null, ['url' => $result['url'], 'expires_at' => $expires]);
            if ($saved) {
                foreach (DB::table('notification_deliveries')->where('funeral_request_id', $id)->where('event_type', 'completed')
                    ->where('state', 'pending')->pluck('id') as $deliveryId) {
                    app(WhatsAppDelivery::class)->dispatch($deliveryId);
                }
            }

            return;
        }

        $code = 'visitation_http_'.$response->status();
        $errors = $response->json('errors');
        if ($response->status() === 422 && is_array($errors)
            && app(FuneralRequests::class)->returnForVisitationCorrection($row, array_keys($errors))) {
            return;
        }
        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            $retryAfter = $response->header('Retry-After');
            $minimum = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: 0) - now()->getTimestamp());
            $this->retry($row, $code, $minimum);
        } else {
            $this->finish($row, 'failed', $code);
        }
    }

    /** @return array<string, mixed> */
    private function payload(stdClass $request): array
    {
        return [
            'request_reference' => $request->reference_code,
            'applicant' => [
                'name' => $request->applicant_name, 'whatsapp' => FuneralRequests::applicantPhoneForApi($request->applicant_whatsapp_e164),
                'relationship_to_deceased' => $request->relationship_to_deceased,
            ],
            'deceased' => ['name' => $request->deceased_name, 'date_of_death' => $request->deceased_date_of_death],
            'burial' => [
                'site' => $request->burial_site, 'zone' => $request->zone, 'lot_number' => $request->lot_number,
                'scheduled_at' => CarbonImmutable::parse($request->current_burial_at, 'UTC')->setTimezone('Asia/Jakarta')->toIso8601String(),
            ],
        ];
    }

    private function configured(): bool
    {
        $url = config('funeral.visitation.url');
        $key = config('funeral.visitation.key');

        return config('funeral.visitation.enabled') && is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null
            && is_string($key) && $key !== '';
    }

    private function due(): Builder
    {
        return DB::table('visitation_plan_requests')->where('state', 'pending')->where('attempt_count', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()->utc()));
    }

    private function retry(stdClass $row, string $code, int $minimum = 0): void
    {
        $delay = max($minimum, 30 * (2 ** ($row->attempt_count - 1)) + random_int(0, 15));
        $this->finish($row, $row->attempt_count >= self::MAX_ATTEMPTS ? 'failed' : 'pending', $code, next: $delay);
    }

    /** @param array<string, mixed> $extra */
    private function finish(stdClass $row, string $state, ?string $code, array $extra = [], ?int $next = null): bool
    {
        $now = now()->utc();

        return DB::table('visitation_plan_requests')->where('funeral_request_id', $row->funeral_request_id)
            ->where('generation', $row->generation)
            ->where('state', 'processing')->where('attempt_count', $row->attempt_count)->update($extra + [
                'state' => $state, 'last_error_code' => $code, 'updated_at' => $now,
                'next_attempt_at' => $state === 'pending' && $next !== null ? $now->copy()->addSeconds($next) : null,
            ]) === 1;
    }
}
