<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FuneralRequests;
use App\Services\WhatsAppDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffFuneralController extends Controller
{
    private const STATUSES = ['new_request', 'contacted', 'in_progress', 'completed', 'cancelled'];

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $month = $today->startOfMonth();
        $period = isset($filters['period']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['period'], 'Asia/Jakarta') : $month;
        $start = $period->utc();
        $end = $period->addMonth()->utc();

        $responseTotal = 0;
        $responseCount = 0;
        foreach (DB::table('funeral_requests')->where('first_contacted_at', '>=', $start)
            ->where('first_contacted_at', '<', $end)->select('id', 'submitted_at', 'first_contacted_at')->lazyById() as $row) {
            $responseTotal += FuneralRequests::responseSeconds(CarbonImmutable::parse($row->submitted_at, 'UTC'), CarbonImmutable::parse($row->first_contacted_at, 'UTC'));
            $responseCount++;
        }

        $periodRows = DB::table('funeral_requests')->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end);
        $analytics = null;
        $deliveryAlerts = null;
        if ($actor->role !== 'pic') {
            $trend = array_fill(1, $period->daysInMonth, 0);
            $distribution = array_fill_keys(self::STATUSES, 0);
            foreach ((clone $periodRows)->select('id', 'status', 'submitted_at')->lazyById() as $row) {
                $trend[CarbonImmutable::parse($row->submitted_at, 'UTC')->setTimezone('Asia/Jakarta')->day]++;
                $distribution[$row->status]++;
            }
            $analytics = ['trend' => array_values($trend), 'distribution' => $distribution];
        }
        $whatsapp = DB::table('notification_deliveries as n')->join('funeral_requests as f', 'f.id', '=', 'n.funeral_request_id')
            ->whereIn('n.state', ['failed', 'unknown'])
            ->whereNull('n.superseded_by')
            ->select(['n.id', 'n.funeral_request_id', 'n.state', 'n.recipient_role', 'n.last_error_code', 'f.reference_code', 'n.updated_at'])
            ->selectRaw("'whatsapp' as source, NULL as validation_fields");
        $visitation = DB::table('visitation_plan_requests as v')->join('funeral_requests as f', 'f.id', '=', 'v.funeral_request_id')
            ->where('v.state', 'failed')
            ->where('f.status', '<>', 'cancelled')
            ->select(['v.funeral_request_id as id', 'v.funeral_request_id', 'v.state'])
            ->selectRaw('NULL as recipient_role')
            ->addSelect(['v.last_error_code', 'f.reference_code', 'v.updated_at'])
            ->selectRaw("'visitation' as source")
            ->addSelect('v.validation_fields');
        if ($actor->role === 'pic') {
            $whatsapp->where(fn (Builder $q) => $q->where(fn (Builder $customer) => $customer->where('n.event_type', 'completed')->where('f.responsible_user_id', $actor->id))
                ->orWhere(fn (Builder $staff) => $staff->where('n.event_type', 'new_request')->where('n.destination_e164', $actor->phone)));
            $visitation->where('f.responsible_user_id', $actor->id)->where('f.status', 'in_progress')->whereNotNull('v.validation_fields');
        }
        $deliveryAlerts = DB::query()->fromSub($whatsapp->unionAll($visitation), 'alerts')->orderByDesc('updated_at')->orderBy('id')
            ->paginate(5, ['*'], 'delivery_page')->withQueryString()->fragment('whatsapp')
            ->through(function (\stdClass $row): \stdClass {
                $row->validation_fields = json_decode($row->validation_fields ?? '[]', true);

                return $row;
            });
        if ($actor->role === 'pic' && $deliveryAlerts->total() === 0) {
            $deliveryAlerts = null;
        }

        return Inertia::render('staff', [
            'period' => $period->format('Y-m'),
            'today' => $today->toDateString(),
            'metrics' => [
                'today' => DB::table('funeral_requests')->where('submitted_at', '>=', $today->utc())->where('submitted_at', '<', $today->addDay()->utc())->count(),
                'new_requests' => DB::table('funeral_requests')->where('status', 'new_request')->count(),
                'period_count' => $analytics === null ? $periodRows->count() : array_sum($analytics['trend']),
                'response_seconds' => $responseCount ? (int) round($responseTotal / $responseCount) : null,
            ],
            'schedule' => $this->summary()->where('f.status', '<>', 'cancelled')
                ->where('f.current_burial_at', '>=', $today->utc())->where('f.current_burial_at', '<', $today->addDays(2)->utc())
                ->orderBy('f.current_burial_at')->orderBy('f.id')->get()->map(function (\stdClass $row): \stdClass {
                    $row->schedule_day = CarbonImmutable::parse($row->current_burial_at, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();

                    return $row;
                }),
            'analytics' => $analytics,
            'deliveryAlerts' => $deliveryAlerts,
        ]);
    }

    public function requests(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...self::STATUSES, 'active'])],
            'site' => ['nullable', 'in:karawang,tangerang'],
            'handler' => ['nullable', 'uuid'],
        ]);
        $rows = $this->summary();
        if (isset($filters['q'])) {
            $query = '%'.strtolower($filters['q']).'%';
            $rows->where(fn (Builder $q) => $q->whereRaw('LOWER(f.reference_code) LIKE ?', [$query])
                ->orWhereRaw('LOWER(f.applicant_name) LIKE ?', [$query])
                ->orWhereRaw('LOWER(f.deceased_name) LIKE ?', [$query]));
        }
        foreach (['status' => 'status', 'site' => 'burial_site', 'handler' => 'responsible_user_id'] as $filter => $column) {
            if ($filter === 'status' && ($filters['status'] ?? null) === 'active') {
                $rows->whereNotIn('f.status', FuneralRequests::FINAL);
            } elseif (isset($filters[$filter])) {
                $rows->where('f.'.$column, $filters[$filter]);
            }
        }

        return Inertia::render('staff-requests', [
            'filters' => $filters,
            'requests' => $rows->orderByDesc('f.submitted_at')->orderByDesc('f.id')->paginate(20)->withQueryString(),
            'handlers' => User::query()->whereIn('role', FuneralRequests::HANDLERS)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $actor = $this->actor($request);
        $record = DB::table('funeral_requests as f')->leftJoin('users as u', 'u.id', '=', 'f.responsible_user_id')
            ->where('f.id', $id)->first(['f.id', 'f.reference_code', 'f.status', 'f.responsible_user_id', 'f.applicant_name', 'f.applicant_whatsapp_e164',
                'f.relationship_to_deceased', 'f.deceased_name', 'f.deceased_date_of_death', 'f.funeral_home_location',
                'f.has_plot', 'f.burial_site', 'f.zone', 'f.lot_number', 'f.paid_in_full', 'f.current_burial_at',
                'f.applicant_estimated_burial_at', 'f.submitted_at', 'f.first_contacted_at', 'f.completed_at', 'f.cancelled_at',
                'f.cancellation_reason', 'f.consented_at', 'u.name as handler_name']);
        abort_if($record === null, 404);
        $canEdit = FuneralRequests::canEdit($actor, $record);
        $canTransfer = $canEdit && $record->responsible_user_id !== null && in_array($actor->role, ['manager', 'superadmin'], true);
        $record->current_local = CarbonImmutable::parse($record->current_burial_at, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i');
        $record->response_seconds = $record->first_contacted_at
            ? FuneralRequests::responseSeconds(CarbonImmutable::parse($record->submitted_at, 'UTC'), CarbonImmutable::parse($record->first_contacted_at, 'UTC'))
            : null;

        $activity = DB::table('request_audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->where('a.funeral_request_id', $id)->orderByDesc('a.occurred_at')->orderByDesc('a.id')
            ->cursorPaginate(10, ['a.id', 'a.action', 'a.occurred_at', 'a.after', 'u.name as actor'], 'activity_cursor');
        $timeline = [
            'next_cursor' => $activity->nextCursor()?->encode(),
            'data' => array_map(function (\stdClass $entry): \stdClass {
                $after = json_decode($entry->after ?? '{}', true);
                $entry->document_type = $after['type'] ?? null;
                $entry->actor ??= ($after['source'] ?? null) === 'manual_test_recovery'
                    ? 'Pemulihan manual atas permintaan pengguna'
                    : (in_array($entry->action, ['submitted', 'document_added'], true) ? 'Pemohon' : 'Sistem');
                unset($entry->after);

                return $entry;
            }, $activity->items()),
        ];

        $visitation = DB::table('visitation_plan_requests')->where('funeral_request_id', $id)
            ->first(['state', 'last_error_code', 'validation_fields']);
        if ($visitation !== null) {
            $visitation->validation_fields = json_decode($visitation->validation_fields ?? '[]', true);
        }

        $notificationFailures = DB::table('notification_deliveries')->where('funeral_request_id', $id)
            ->whereIn('state', ['failed', 'unknown'])->whereNull('superseded_by')->orderByDesc('updated_at')
            ->get(['id', 'event_type', 'state', 'recipient_role', 'last_error_code', 'provider_message_id', 'destination_e164'])
            ->filter(fn (\stdClass $delivery): bool => $actor->role !== 'pic' || WhatsAppDelivery::mayRetry($actor, $record, $delivery))
            ->map(function (\stdClass $delivery) use ($actor, $record): \stdClass {
                $delivery->can_retry = $record->status !== 'cancelled' && $delivery->provider_message_id === null && WhatsAppDelivery::mayRetry($actor, $record, $delivery);
                unset($delivery->provider_message_id, $delivery->destination_e164);

                return $delivery;
            })->values();

        return Inertia::render('staff-request', [
            'request' => $record,
            'canEdit' => $canEdit,
            'canProcess' => FuneralRequests::canProcess($actor, $record),
            'canTransfer' => $canTransfer,
            'handlers' => $canTransfer ? User::query()->where('active', true)->whereIn('role', FuneralRequests::HANDLERS)->orderBy('name')->get(['id', 'name', 'role']) : [],
            'notice' => $request->session()->get('workflow_notice'),
            'visitation' => $record->status === 'cancelled' ? null : $visitation,
            'notificationFailures' => $notificationFailures,
            'documents' => DB::table('request_documents')->where('funeral_request_id', $id)->whereNull('superseded_at')
                ->orderByDesc('uploaded_at')->get(['id', 'type', 'original_file_name', 'validation_state', 'uploaded_at', 'detected_mime']),
            'activity' => $request->filled('activity_cursor') ? Inertia::merge($timeline)->append('data', matchOn: 'id') : $timeline,
        ]);
    }

    public function transition(Request $request, string $id, FuneralRequests $service): HttpResponse
    {
        $data = $request->validate([
            'expected_status' => ['required', Rule::in(self::STATUSES)],
            'target' => ['required', 'in:contacted,in_progress,completed,cancelled'],
            'reason' => ['required_if:target,cancelled', 'nullable', 'string', 'max:2000'],
            'contact_evidence' => ['nullable', 'file', 'max:5120'],
        ]);
        try {
            $service->transition($this->actor($request), $id, $data['expected_status'], $data['target'], $data['contact_evidence'] ?? null, $data['reason'] ?? null);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409 || ! $request->header('X-Inertia')) {
                throw $error;
            }

            $request->session()->flash('workflow_notice', 'Status pengajuan sudah berubah. Periksa kondisi terbaru sebelum mencoba lagi.');

            return Inertia::location(route('staff.requests.show', $id));
        }

        return to_route('staff.requests.show', $id);
    }

    public function retryNotification(Request $request, string $id, string $delivery, WhatsAppDelivery $service): HttpResponse
    {
        $data = $request->validate(['acknowledge_uncertain' => ['sometimes', 'boolean']]);
        try {
            $service->retryNotification($this->actor($request), $id, $delivery, (bool) ($data['acknowledge_uncertain'] ?? false));
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409 || ! $request->header('X-Inertia')) {
                throw $error;
            }
            $request->session()->flash('workflow_notice', $error->getMessage());

            return Inertia::location(route('staff.requests.show', $id));
        }

        return to_route('staff.requests.show', $id)->with('workflow_notice', 'Pesan dijadwalkan ulang. Tidak perlu menekan tombol kembali.');
    }

    public function update(Request $request, string $id, FuneralRequests $service): RedirectResponse
    {
        $data = $request->only([
            'applicant_name', 'applicant_whatsapp', 'relationship_to_deceased', 'deceased_name',
            'deceased_date_of_death', 'funeral_home_location', 'has_plot', 'burial_site', 'zone', 'lot_number', 'paid_in_full', 'current_burial_at',
        ]);
        $service->edit($this->actor($request), $id, $data);

        return to_route('staff.requests.show', $id);
    }

    public function transfer(Request $request, string $id, FuneralRequests $service): RedirectResponse
    {
        $data = $request->validate(['recipient_id' => ['required', 'uuid']]);
        $service->transfer($this->actor($request), $id, $data['recipient_id']);

        return to_route('staff.requests.show', $id);
    }

    public function attach(Request $request, string $id, FuneralRequests $service): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'string'], 'document' => ['required', 'file', 'max:5120']]);
        /** @var UploadedFile $file */
        $file = $data['document'];
        $service->attach($this->actor($request), $id, $data['type'], $file);

        return to_route('staff.requests.show', $id);
    }

    public function download(Request $request, string $id, string $document, FuneralRequests $service): HttpResponse
    {
        $thumbnail = $request->boolean('thumbnail');
        $download = $service->openDocument($this->actor($request), $id, $document, $thumbnail);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        $stream = function () use ($download) {
            try {
                fpassthru($download['stream']);
            } finally {
                fclose($download['stream']);
            }
        };
        if ($thumbnail) {
            return response()->stream($stream, 200, $headers + ['Content-Type' => $download['mime']]);
        }

        return response()->streamDownload($stream, str_replace(['/', '\\', "\r", "\n"], '_', $download['filename']), $headers + ['Content-Type' => 'application/octet-stream']);
    }

    private function summary(): Builder
    {
        return DB::table('funeral_requests as f')->leftJoin('users as u', 'u.id', '=', 'f.responsible_user_id')
            ->select(['f.id', 'f.reference_code', 'f.status', 'f.deceased_name', 'f.submitted_at', 'f.current_burial_at', 'f.burial_site', 'u.name as handler_name']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
