<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class FuneralRequests
{
    public const HANDLERS = ['pic', 'manager', 'superadmin'];

    public const FINAL = ['completed', 'cancelled'];

    private const DOCUMENTS = ['ownership_certificate', 'plot_owner_ktp', 'deceased_ktp', 'hospital_death_certificate', 'embassy_death_certificate'];

    public const CONSENT = 'Saya telah membaca, memahami, dan menyetujui penggunaan data pribadi untuk keperluan pelayanan pemakaman Lestari Memorial Park.';

    public static function normalizeApplicantPhone(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }
        $phone = preg_replace('/[ ()-]+/', '', trim($value)) ?? '';
        $digits = ltrim($phone, '+');
        if (! preg_match('/^(?:08|\+?62)[0-9]+$/D', $phone) || strlen($digits) < 10 || strlen($digits) > 13) {
            return null;
        }

        return str_starts_with($digits, '08') ? '+62'.substr($digits, 1) : '+'.$digits;
    }

    public static function applicantPhoneForApi(string $normalized): string
    {
        $digits = ltrim($normalized, '+');

        return str_starts_with($digits, '628') ? '0'.substr($digits, 2) : $digits;
    }

    /** @param array<string, mixed> $input
     * @param  array<string, UploadedFile>  $documents
     * @param  array{digest: string, fingerprint: string}|null  $replay
     */
    public function submit(array $input, array $documents = [], ?array $replay = null): string
    {
        $data = $this->submissionFields($input);
        $estimate = CarbonImmutable::parse($data['estimated_burial_date'].' '.$data['estimated_burial_time'], 'Asia/Jakarta')->utc();
        unset($data['estimated_burial_date'], $data['estimated_burial_time']);
        $now = now()->utc();
        if ($estimate->lte($now)) {
            throw ValidationException::withMessages(['estimated_burial_time' => 'Perkiraan waktu pemakaman harus di masa depan.']);
        }

        $id = (string) Str::uuid();
        $reference = 'LMP-'.Str::upper(Str::random(12));
        $staged = [];
        try {
            $inspected = [];
            foreach ($documents as $type => $file) {
                $this->validateDocumentType($type);
                if ((! $data['has_plot'] && $type === 'plot_owner_ktp')
                    || ($type === 'ownership_certificate' && (! $data['has_plot'] || ! ($data['paid_in_full'] ?? false)))) {
                    continue;
                }
                $inspected[$type] = $this->inspect($type, $file, $replay !== null);
            }
            foreach ($inspected as $type => $inspection) {
                $staged[] = $this->stage($id, $type, $documents[$type], null, $inspection);
            }

            DB::transaction(function () use ($id, $reference, $data, $estimate, $now, $staged, $replay) {
                DB::table('funeral_requests')->insert($data + [
                    'id' => $id, 'reference_code' => $reference, 'status' => 'new_request',
                    'applicant_estimated_burial_at' => $estimate, 'current_burial_at' => $estimate,
                    'consent_text_version' => '1', 'consent_text_snapshot' => self::CONSENT,
                    'consented_at' => $now, 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($replay !== null) {
                    DB::table('public_submission_keys')->where('key_digest', $replay['digest'])->where('expires_at', '<=', $now)->delete();
                    DB::table('public_submission_keys')->insert([
                        'key_digest' => $replay['digest'], 'payload_fingerprint' => $replay['fingerprint'],
                        'funeral_request_id' => $id, 'expires_at' => $now->copy()->addDay(),
                    ]);
                }
                $this->audit($id, null, 'submitted');
                foreach ($staged as $document) {
                    $this->saveDocument($document);
                }

                $recipients = User::query()->where('active', true)->whereIn('role', ['pic', 'manager', 'admin'])
                    ->get(['id', 'phone', 'role']);
                foreach ($recipients as $recipient) {
                    $this->intent($id, 'new_request', $recipient->phone, $recipient->role, $recipient->id);
                }
            });
        } catch (Throwable $error) {
            $this->discard($staged);
            throw $error;
        }

        return $reference;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submissionFields(array $input): array
    {
        $data = $this->details($input, true);
        $time = Validator::make($input, [
            'estimated_burial_date' => ['required', 'date_format:Y-m-d'],
            'estimated_burial_time' => ['required', 'date_format:H:i'],
            'consent_accepted' => ['accepted'],
        ])->validate();
        if ($time['estimated_burial_date'] < $data['deceased_date_of_death']) {
            throw ValidationException::withMessages(['estimated_burial_date' => 'Tanggal pemakaman tidak boleh sebelum tanggal meninggal.']);
        }
        unset($time['consent_accepted']);
        $data['has_plot'] = (bool) $data['has_plot'];
        if (isset($data['paid_in_full'])) {
            $data['paid_in_full'] = (bool) $data['paid_in_full'];
        }

        return $data + $time;
    }

    public function transition(User $actor, string $id, string $expected, string $target, ?UploadedFile $evidence = null, ?string $reason = null): void
    {
        $document = null;
        try {
            DB::transaction(function () use ($actor, $id, $expected, $target, $evidence, $reason, &$document) {
                $actor = $this->lockActor($actor);
                $request = $this->locked($id);
                $this->authorizeMutation($actor, $request, checkOwner: false);
                abort_if($request->status !== $expected, 409, 'Status pengajuan telah berubah.');
                $this->authorizeMutation($actor, $request);
                $next = ['new_request' => 'contacted', 'contacted' => 'in_progress', 'in_progress' => 'completed'];
                if ($target !== 'cancelled' && ($next[$expected] ?? null) !== $target) {
                    throw ValidationException::withMessages(['status' => 'Perubahan status tidak diizinkan.']);
                }

                $now = now()->utc();
                $changes = ['status' => $target, 'updated_at' => $now];
                if ($target === 'contacted') {
                    if ($evidence === null) {
                        throw ValidationException::withMessages(['contact_evidence' => 'Foto bukti kontak wajib diunggah.']);
                    }
                    $document = $this->stage($id, 'contact_evidence', $evidence, $actor->id);
                    $this->saveDocument($document);
                    $changes += ['responsible_user_id' => $actor->id, 'first_contacted_by' => $actor->id, 'first_contacted_at' => $now];
                } elseif ($target === 'completed') {
                    $this->validateCompletion($request);
                    $changes['completed_at'] = $now;
                    $this->intent($id, 'completed', $request->applicant_whatsapp_e164);
                } elseif ($target === 'cancelled') {
                    $validated = Validator::make(['reason' => trim($reason ?? '')], ['reason' => ['required', 'string', 'max:2000']])->validate();
                    $changes += ['cancelled_at' => $now, 'cancellation_reason' => $validated['reason']];
                }

                DB::table('funeral_requests')->where('id', $id)->update($changes);
                if ($target === 'completed') {
                    app(VisitationPlans::class)->ensure($id);
                }
                $this->audit($id, $actor->id, $target, ['status' => $expected], ['status' => $target]);
            });
        } catch (Throwable $error) {
            $this->discard($document === null ? [] : [$document]);
            throw $error;
        }
    }

    /** @param list<int|string> $fields */
    public function returnForVisitationCorrection(stdClass $attempt, array $fields): bool
    {
        if ($fields === [] || array_diff($fields, ['burial.site', 'burial.zone', 'burial.lot_number']) !== []) {
            return false;
        }

        return DB::transaction(function () use ($attempt, $fields): bool {
            $request = $this->locked($attempt->funeral_request_id);
            $plan = DB::table('visitation_plan_requests')->where('funeral_request_id', $request->id)->lockForUpdate()->first();
            $deliveries = DB::table('notification_deliveries')->where('funeral_request_id', $request->id)
                ->where('event_type', 'completed')->lockForUpdate()->get();
            if ($request->status !== 'completed' || $plan === null || $plan->state !== 'processing'
                || $plan->generation !== $attempt->generation || $plan->attempt_count !== $attempt->attempt_count
                || $plan->url !== null || $deliveries->count() !== 1
                || $deliveries->contains(fn (stdClass $delivery): bool => $delivery->state !== 'pending'
                    || $delivery->attempt_count !== 0 || $delivery->provider_message_id !== null)) {
                return false;
            }

            $now = now()->utc();
            DB::table('visitation_plan_requests')->where('funeral_request_id', $request->id)->update([
                'state' => 'failed', 'last_error_code' => 'visitation_http_422',
                'validation_fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'next_attempt_at' => null, 'updated_at' => $now,
            ]);
            DB::table('funeral_requests')->where('id', $request->id)->update([
                'status' => 'in_progress', 'completed_at' => null, 'updated_at' => $now,
            ]);
            DB::table('notification_deliveries')->where('id', $deliveries->first()->id)->delete();
            $this->audit($request->id, null, 'visitation_correction_required', ['status' => 'completed'], [
                'status' => 'in_progress', 'validation_fields' => $fields,
            ]);

            return true;
        });
    }

    /** @param array<string, mixed> $input */
    public function edit(User $actor, string $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input) {
            $actor = $this->lockActor($actor);
            $request = $this->locked($id);
            $this->authorizeEdit($actor, $request);
            $data = $this->details($input, false, (bool) $request->has_plot);
            if (isset($data['current_burial_at']) || isset($data['deceased_date_of_death'])) {
                $date = CarbonImmutable::parse($data['current_burial_at'] ?? $request->current_burial_at, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();
                if ($date < ($data['deceased_date_of_death'] ?? $request->deceased_date_of_death)) {
                    $field = isset($data['deceased_date_of_death']) ? 'deceased_date_of_death' : 'current_burial_at';
                    throw ValidationException::withMessages([$field => 'Tanggal pemakaman tidak boleh sebelum tanggal meninggal.']);
                }
            }
            $before = [];
            $after = [];
            if (array_key_exists('current_burial_at', $data)) {
                $previous = CarbonImmutable::parse($request->current_burial_at, 'UTC')->utc();
                $current = CarbonImmutable::parse($data['current_burial_at'])->utc();
                if (! $previous->equalTo($current)) {
                    $data['current_burial_at'] = $current;
                    $before['current_burial_at'] = $previous->toIso8601String();
                    $after['current_burial_at'] = $current->toIso8601String();
                } else {
                    unset($data['current_burial_at']);
                }
            }
            if ($data !== []) {
                DB::table('funeral_requests')->where('id', $id)->update($data + ['updated_at' => now()->utc()]);
                $this->audit($id, $actor->id, 'edited', $before, $after + ['fields' => array_keys($data)]);
            }
        });
    }

    public function transfer(User $actor, string $id, string $recipientId): void
    {
        DB::transaction(function () use ($actor, $id, $recipientId) {
            $users = User::query()->whereIn('id', [$actor->id, $recipientId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $users->get($actor->id);
            abort_unless($currentActor && $currentActor->active && $currentActor->access_version === $actor->access_version && in_array($currentActor->role, ['manager', 'superadmin'], true), 403);
            $request = $this->locked($id);
            $this->authorizeMutation($currentActor, $request);
            $recipient = $users->get($recipientId);
            if ($request->responsible_user_id === null || ! $recipient || ! $recipient->active || ! in_array($recipient->role, self::HANDLERS, true)) {
                throw ValidationException::withMessages(['responsible_user_id' => 'Pengajuan harus sudah diklaim dan penerima harus berwenang serta aktif.']);
            }
            if ($request->responsible_user_id !== $recipientId) {
                DB::table('funeral_requests')->where('id', $id)->update(['responsible_user_id' => $recipientId, 'updated_at' => now()->utc()]);
                $this->audit($id, $actor->id, 'transferred', ['responsible_user_id' => $request->responsible_user_id], ['responsible_user_id' => $recipientId]);
            }
        });
    }

    public function attach(User $actor, string $id, string $type, UploadedFile $file): void
    {
        $this->validateDocumentType($type);
        $document = null;
        try {
            DB::transaction(function () use ($actor, $id, $type, $file, &$document) {
                $actor = $this->lockActor($actor);
                $request = $this->locked($id);
                $this->authorizeEdit($actor, $request);
                $document = $this->stage($id, $type, $file, $actor->id);
                $this->saveDocument($document);
            });
        } catch (Throwable $error) {
            $this->discard($document === null ? [] : [$document]);
            throw $error;
        }
    }

    /** @return array{stream: resource, filename: string, mime: string} */
    public function openDocument(User $actor, string $id, string $documentId, bool $thumbnail = false): array
    {
        return DB::transaction(function () use ($actor, $id, $documentId, $thumbnail) {
            $actor = $this->lockActor($actor);
            abort_unless(in_array($actor->role, User::ROLES, true), 403);
            $this->locked($id);
            $document = DB::table('request_documents')->where(['id' => $documentId, 'funeral_request_id' => $id])
                ->where('validation_state', 'accepted')->whereNull('superseded_at')->first();
            abort_if($document === null, 404);
            abort_if($thumbnail && ! in_array($document->detected_mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
            $stream = Storage::disk($document->storage_disk)->readStream($document->object_key);
            abort_unless(is_resource($stream), 404);
            try {
                $this->audit($id, $actor->id, 'document_accessed', after: ['document_id' => $documentId, 'type' => $document->type]);
            } catch (Throwable $error) {
                fclose($stream);
                throw $error;
            }

            return ['stream' => $stream, 'filename' => $document->original_file_name, 'mime' => $document->detected_mime];
        });
    }

    public static function responseSeconds(DateTimeInterface $submitted, DateTimeInterface $contacted): int
    {
        $start = CarbonImmutable::instance($submitted)->setTimezone('Asia/Jakarta');
        $end = CarbonImmutable::instance($contacted)->setTimezone('Asia/Jakarta');
        $seconds = 0;
        for ($day = $start->startOfDay(); $day->lt($end); $day = $day->addDay()) {
            $from = max($start->getTimestamp(), $day->setHour(8)->getTimestamp());
            $until = min($end->getTimestamp(), $day->setHour(17)->getTimestamp());
            $seconds += max(0, $until - $from);
        }

        return $seconds;
    }

    private function lockActor(User $actor): User
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->active && $current->access_version === $actor->access_version, 403);

        return $current;
    }

    private function locked(string $id): stdClass
    {
        $request = DB::table('funeral_requests')->where('id', $id)->lockForUpdate()->first();
        abort_if($request === null, 404);

        return $request;
    }

    public static function canEdit(User $actor, stdClass $request): bool
    {
        return in_array($request->status, ['contacted', 'in_progress'], true)
            && $request->responsible_user_id !== null && self::canProcess($actor, $request);
    }

    public static function canProcess(User $actor, stdClass $request): bool
    {
        return $actor->active && in_array($actor->role, self::HANDLERS, true)
            && ! in_array($request->status, self::FINAL, true)
            && ($actor->role !== 'pic' || $request->responsible_user_id === null || $request->responsible_user_id === $actor->id);
    }

    private function authorizeEdit(User $actor, stdClass $request): void
    {
        $this->authorizeMutation($actor, $request);
        abort_unless(self::canEdit($actor, $request), 403, 'Klaim pengajuan dan tandai sudah dihubungi sebelum mengubah data.');
    }

    private function authorizeMutation(User $actor, stdClass $request, bool $checkOwner = true): void
    {
        abort_unless(in_array($actor->role, self::HANDLERS, true), 403);
        if ($checkOwner) {
            abort_if(in_array($request->status, self::FINAL, true), 409, 'Pengajuan sudah final.');
            abort_unless(self::canProcess($actor, $request), 403);
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function details(array $input, bool $submitting, bool $existingPlot = false): array
    {
        $required = $submitting ? 'required' : 'sometimes';
        $data = Validator::make($input, [
            'applicant_name' => [$required, 'required', 'string', 'max:255'],
            'applicant_whatsapp' => [$required, 'required', 'string'],
            'relationship_to_deceased' => [$required, 'required', 'string', 'max:100'],
            'deceased_name' => [$required, 'required', 'string', 'max:255'],
            'deceased_date_of_death' => [$required, 'required', 'date_format:Y-m-d'],
            'funeral_home_location' => [$required, 'required', 'string', 'max:255'],
            'has_plot' => [$required, 'required', 'boolean'],
            'burial_site' => ['exclude_if:has_plot,false,0', 'nullable', 'in:karawang,tangerang'],
            'zone' => ['exclude_if:has_plot,false,0', 'nullable', 'string', 'max:255'],
            'lot_number' => ['exclude_if:has_plot,false,0', 'nullable', 'string', 'max:255'],
            'paid_in_full' => ['exclude_if:has_plot,false,0', 'nullable', 'boolean'],
            ...($submitting ? [] : ['current_burial_at' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP']]),
        ])->validate();

        if (array_key_exists('applicant_whatsapp', $data)) {
            $data['applicant_whatsapp_e164'] = self::normalizeApplicantPhone($data['applicant_whatsapp'])
                ?? throw ValidationException::withMessages(['applicant_whatsapp' => 'Gunakan nomor WhatsApp Indonesia berawalan 08, 62, atau +62 dengan 10 sampai 13 digit angka.']);
            unset($data['applicant_whatsapp']);
        }
        if (! (bool) ($data['has_plot'] ?? $existingPlot) && array_intersect(['has_plot', 'burial_site', 'zone', 'lot_number', 'paid_in_full'], array_keys($input)) !== []) {
            $data['burial_site'] = $data['zone'] = $data['lot_number'] = $data['paid_in_full'] = null;
        }

        return $data;
    }

    private function validateCompletion(stdClass $request): void
    {
        $blockers = [];
        if (self::normalizeApplicantPhone(self::applicantPhoneForApi($request->applicant_whatsapp_e164)) === null) {
            $blockers['applicant_whatsapp'] = 'Nomor WhatsApp Indonesia yang sesuai kontrak ziarah wajib diisi.';
        }
        if (trim($request->relationship_to_deceased) === '' || mb_strlen($request->relationship_to_deceased) > 100) {
            $blockers['relationship_to_deceased'] = 'Hubungan wajib diisi dengan maksimal 100 karakter.';
        }
        if ($request->current_burial_at !== null
            && CarbonImmutable::parse($request->current_burial_at, 'UTC')->setTimezone('Asia/Jakarta')->toDateString() < $request->deceased_date_of_death) {
            $blockers['current_burial_at'] = 'Tanggal pemakaman tidak boleh sebelum tanggal meninggal.';
        }
        foreach ([
            'has_plot' => $request->has_plot,
            'burial_site' => $request->burial_site,
            'zone' => trim($request->zone ?? '') !== '',
            'lot_number' => trim($request->lot_number ?? '') !== '',
            'paid_in_full' => $request->paid_in_full,
            'current_burial_at' => $request->current_burial_at,
        ] as $field => $valid) {
            if (! $valid) {
                $blockers[$field] = 'Wajib dilengkapi sebelum penyelesaian.';
            }
        }

        $documents = DB::table('request_documents')->where('funeral_request_id', $request->id)
            ->where('validation_state', 'accepted')->whereNull('superseded_at')->get()->keyBy('type');
        foreach (array_slice(self::DOCUMENTS, 0, 4) as $type) {
            $document = $documents->get($type);
            if (! $document || ! Storage::disk($document->storage_disk)->exists($document->object_key)) {
                $blockers[$type] = 'Dokumen yang valid wajib tersedia.';
            }
        }
        if ($blockers !== []) {
            throw ValidationException::withMessages($blockers);
        }
    }

    private function validateDocumentType(string $type): void
    {
        if (! in_array($type, self::DOCUMENTS, true)) {
            throw ValidationException::withMessages(['documents' => 'Jenis dokumen tidak diizinkan.']);
        }
    }

    /** @return array{mime: string, state: string} */
    private function inspect(string $type, UploadedFile $file, bool $requireScanner = false): array
    {
        $imageTypes = ['jpg', 'jpeg', 'png', 'webp'];
        $types = $type === 'contact_evidence' ? $imageTypes : [...$imageTypes, 'pdf'];
        Validator::make([$type => $file], [$type => ['required', File::types($types)->max('5mb'), 'extensions:'.implode(',', $types)]])->validate();
        $mime = $file->getMimeType();
        if ($mime !== 'application/pdf' && @getimagesize($file->getPathname()) === false) {
            throw ValidationException::withMessages([$type => 'Berkas gambar tidak valid.']);
        }

        $state = 'accepted';
        if ($mime === 'application/pdf') {
            if ($requireScanner) {
                $size = $file->getSize();
                $path = $file->getPathname();
                $header = file_get_contents($path, false, null, 0, 5);
                $tail = file_get_contents($path, false, null, max(0, $size - 2048));
                if ($header !== '%PDF-' || ! is_string($tail) || ! str_ends_with(rtrim($tail), '%%EOF')) {
                    throw ValidationException::withMessages([$type => 'Format PDF tidak valid.']);
                }
            }
            $scanner = config('funeral.pdf_scanner');
            if (! is_string($scanner) || $scanner === '') {
                abort_if($requireScanner, 503, 'Pemeriksaan PDF sedang tidak tersedia. Coba lagi atau gunakan foto.');
                $state = 'pending';
            } else {
                try {
                    $command = [$scanner, '--no-summary'];
                    if (strtolower(pathinfo($scanner, PATHINFO_FILENAME)) === 'clamdscan') {
                        $command[] = '--stream';
                    }

                    $exit = Process::timeout(20)->run([...$command, $file->getPathname()])->exitCode();
                } catch (\RuntimeException) {
                    abort(503, 'Pemeriksaan PDF sedang tidak tersedia.');
                }
                if ($exit === 1) {
                    throw ValidationException::withMessages([$type => 'PDF ditolak oleh pemeriksaan keamanan.']);
                }
                abort_unless($exit === 0, 503, 'Pemeriksaan PDF sedang tidak tersedia.');
            }
        }

        return ['mime' => (string) $mime, 'state' => $state];
    }

    /** @param array{mime: string, state: string}|null $inspection
     * @return array<string, mixed>
     */
    private function stage(string $id, string $type, UploadedFile $file, ?string $actor, ?array $inspection = null): array
    {
        $inspection ??= $this->inspect($type, $file);
        $disk = config('funeral.document_disk');
        abort_if(app()->isProduction() && $disk !== 's3', 503, 'Penyimpanan privat belum dikonfigurasi.');
        abort_if($disk === 'public', 503);
        $key = 'funeral-documents/'.Str::uuid();
        $document = [
            'id' => (string) Str::uuid(), 'funeral_request_id' => $id, 'type' => $type,
            'storage_disk' => $disk, 'object_key' => $key, 'original_file_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'declared_mime' => Str::limit($file->getClientMimeType(), 255, ''), 'detected_mime' => $inspection['mime'],
            'byte_size' => $file->getSize(), 'uploaded_by_user_id' => $actor, 'uploaded_at' => now()->utc(),
            'validation_state' => $inspection['state'],
        ];
        try {
            $storage = Storage::disk($disk);
            if ($storage instanceof AwsS3V3Adapter) {
                // R2 uses bucket privacy and does not support an object ACL.
                $storage->getClient()->putObject([
                    'Bucket' => config("filesystems.disks.$disk.bucket"),
                    'Key' => $key,
                    'SourceFile' => $file->getPathname(),
                    'ContentType' => $inspection['mime'],
                ]);
            } else {
                abort_if($storage->putFileAs('funeral-documents', $file, basename($key), 'private') === false, 503, 'Penyimpanan dokumen gagal.');
            }
        } catch (Throwable $error) {
            $this->discard([$document]);
            report_if(! $error instanceof HttpException, new \RuntimeException('Private document upload failed.'));
            abort(503, 'Penyimpanan dokumen gagal.');
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    private function saveDocument(array $document): void
    {
        if ($document['type'] !== 'contact_evidence' && $document['validation_state'] === 'accepted') {
            DB::table('request_documents')->where('funeral_request_id', $document['funeral_request_id'])
                ->where('type', $document['type'])->where('validation_state', 'accepted')->whereNull('superseded_at')
                ->update(['superseded_at' => now()->utc()]);
        }
        DB::table('request_documents')->insert($document);
        $this->audit($document['funeral_request_id'], $document['uploaded_by_user_id'], 'document_added', after: ['document_id' => $document['id'], 'type' => $document['type']]);
    }

    /** @param list<array<string, mixed>> $documents */
    private function discard(array $documents): void
    {
        foreach ($documents as $document) {
            try {
                Storage::disk($document['storage_disk'])->delete($document['object_key']);
            } catch (Throwable) {
                logger()->warning('Funeral document cleanup failed.');
            }
        }
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $id, ?string $actor, string $action, array $before = [], array $after = []): void
    {
        DB::table('request_audit_logs')->insert([
            'id' => (string) Str::uuid(), 'funeral_request_id' => $id, 'actor_user_id' => $actor, 'action' => $action,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'occurred_at' => now()->utc(),
        ]);
    }

    private function intent(string $id, string $event, string $phone, ?string $role = null, ?string $recipientId = null): void
    {
        $now = now()->utc();
        $deliveryId = (string) Str::uuid();
        $request = DB::table('funeral_requests')->where('id', $id)->first();
        $parameters = $event === 'new_request' && $request !== null ? WhatsAppDelivery::newRequestParameters($request) : null;
        DB::table('notification_deliveries')->insert([
            'id' => $deliveryId, 'funeral_request_id' => $id, 'event_type' => $event,
            'template_parameters' => $parameters === null ? null : json_encode($parameters, JSON_THROW_ON_ERROR),
            'recipient_role' => $role, 'destination_e164' => $phone, 'template_name' => config("funeral.templates.$event"),
            'idempotency_key' => "$id:$event:".($recipientId ?? 'customer'),
            'state' => 'pending', 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::afterCommit(fn () => app(WhatsAppDelivery::class)->dispatch($deliveryId));
    }
}
