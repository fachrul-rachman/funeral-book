import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CircleDot, History, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import StaffLayout from '../components/staff-layout';
import StaffRequestData, {
    DetailValue,
} from '../components/staff-request-data';
import type { RequestData } from '../components/staff-request-data';
import StaffRequestDocuments, {
    FilePicker,
} from '../components/staff-request-documents';
import type { RequestDocument } from '../components/staff-request-documents';
import {
    buttonClass,
    dateTime,
    documentLabels,
    duration,
    fieldClass,
    linkClass,
    statusColors,
    statuses,
    visitationCorrectionMessage,
} from '../lib/funeral';

type RequestDetail = RequestData & {
    id: string;
    reference_code: string;
    status: string;
    submitted_at: string;
    handler_name: string | null;
    responsible_user_id: string | null;
    response_seconds: number | null;
    first_contacted_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
};
type Activity = {
    data: {
        id: string;
        action: string;
        actor: string;
        occurred_at: string;
        document_type: string | null;
    }[];
    next_cursor: string | null;
};
type Props = {
    request: RequestDetail;
    canEdit: boolean;
    canProcess: boolean;
    canTransfer: boolean;
    notice: string | null;
    visitation: {
        state: string;
        last_error_code: string | null;
        validation_fields: string[];
    } | null;
    handlers: { id: string; name: string; role: string }[];
    documents: RequestDocument[];
    activity: Activity;
    notificationFailures: NotificationFailure[];
};

type NotificationFailure = {
    id: string;
    event_type: 'new_request' | 'completed';
    state: 'failed' | 'unknown';
    recipient_role: string | null;
    last_error_code: string | null;
    can_retry: boolean;
};

function NotificationRetry({
    delivery,
    url,
}: {
    delivery: NotificationFailure;
    url: string;
}) {
    const form = useForm({ acknowledge_uncertain: false });
    function retry(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const uncertain = delivery.state === 'unknown';
        if (
            uncertain &&
            !window.confirm(
                'Pesan sebelumnya mungkin sudah terkirim. Mengirim ulang dapat membuat penerima menerima pesan ganda. Tetap kirim ulang?',
            )
        )
            return;
        form.transform(() => ({ acknowledge_uncertain: uncertain }));
        form.post(url + '/notifikasi/' + delivery.id + '/kirim-ulang', {
            preserveScroll: true,
        });
    }
    return (
        <li className="space-y-2 py-3 first:pt-0 last:pb-0">
            <p className="text-sm font-medium">
                {delivery.event_type === 'completed'
                    ? 'Pesan customer'
                    : 'Notifikasi tim (' +
                      (delivery.recipient_role?.toUpperCase() ?? 'Staf') +
                      ')'}
            </p>
            <p className="text-sm text-navy/80">
                {delivery.state === 'unknown'
                    ? 'Hasil pengiriman belum diketahui. Pesan mungkin sudah terkirim.'
                    : 'Pesan belum berhasil dikirim. Periksa penyebabnya sebelum mencoba lagi.'}
            </p>
            {delivery.last_error_code && (
                <p className="text-xs text-navy/65">
                    Kode: {delivery.last_error_code}
                </p>
            )}
            {delivery.can_retry && (
                <form onSubmit={retry} className="space-y-2">
                    {Object.values(form.errors).map((message) => (
                        <p
                            key={message}
                            role="alert"
                            className="text-sm text-red-900"
                        >
                            {message}
                        </p>
                    ))}
                    <button
                        className={buttonClass + ' w-full justify-center'}
                        disabled={form.processing}
                    >
                        <RefreshCw className="size-4" aria-hidden="true" />
                        {form.processing
                            ? 'Menjadwalkan…'
                            : delivery.state === 'unknown'
                              ? 'Konfirmasi dan kirim ulang'
                              : 'Coba kirim ulang'}
                    </button>
                </form>
            )}
        </li>
    );
}

const activityLabels: Record<string, string> = {
    submitted: 'Pengajuan diterima',
    contacted: 'Pengajuan diklaim dan pemohon dihubungi',
    in_progress: 'Pengajuan mulai diproses',
    completed: 'Pengajuan diselesaikan',
    test_rollback: 'Pengajuan uji dikembalikan ke Sedang diproses',
    visitation_correction_required:
        'Data lahan ditolak aplikasi ziarah. Pengajuan dikembalikan ke Sedang diproses',
    notification_retry_requested: 'Pengiriman ulang WhatsApp diminta',
    cancelled: 'Pengajuan dibatalkan',
    edited: 'Data diperbarui',
    transferred: 'Penanggung jawab dialihkan',
    schedule_confirmed: 'Jadwal dikonfirmasi',
    document_added: 'Dokumen diunggah',
    document_accessed: 'Dokumen dilihat',
};

const validationLabels: Record<string, string> = {
    applicant_whatsapp: 'Nomor WhatsApp pemohon',
    relationship_to_deceased: 'Hubungan dengan almarhum/almarhumah',
    has_plot: 'Kepemilikan lahan',
    burial_site: 'Lokasi makam',
    zone: 'Zona',
    lot_number: 'Nomor lahan',
    paid_in_full: 'Pelunasan',
    current_burial_at: 'Jadwal pemakaman',
    reason: 'Alasan pembatalan',
    ...documentLabels,
};

function StatusPanel({
    request,
    canProcess,
    canTransfer,
    handlers,
    notice,
    visitation,
    notificationFailures,
    url,
}: Pick<
    Props,
    | 'request'
    | 'canProcess'
    | 'canTransfer'
    | 'handlers'
    | 'notice'
    | 'visitation'
    | 'notificationFailures'
> & { url: string }) {
    const status = useForm({
        expected_status: request.status,
        target: '',
        reason: '',
        contact_evidence: null as File | null,
    });
    const transfer = useForm({ recipient_id: '' });
    const next =
        request.status === 'new_request'
            ? 'contacted'
            : request.status === 'contacted'
              ? 'in_progress'
              : 'completed';

    function changeStatus(event: FormEvent<HTMLFormElement>, target: string) {
        event.preventDefault();
        if (target === 'contacted' && !status.data.contact_evidence) {
            status.setError(
                'contact_evidence',
                'Pilih foto bukti kontak terlebih dahulu.',
            );
            return;
        }
        if (
            target === 'completed' &&
            !window.confirm('Konfirmasi bahwa pemakaman telah selesai?')
        )
            return;
        if (
            target === 'cancelled' &&
            !window.confirm('Konfirmasi pembatalan pengajuan ini?')
        )
            return;
        status.transform((data) => ({
            expected_status: request.status,
            target,
            reason: target === 'cancelled' ? data.reason : '',
            contact_evidence:
                target === 'contacted' ? data.contact_evidence : null,
        }));
        status.post(url + '/status', {
            preserveScroll: true,
            onSuccess: () => status.reset(),
        });
    }

    return (
        <section className="order-1 min-w-0 space-y-4 rounded-lg border border-navy/15 bg-white p-4 sm:p-5">
            <h2 className="flex items-center gap-2 text-lg font-semibold">
                <CircleDot className="size-5" aria-hidden="true" /> Status dan
                tindakan
            </h2>
            <dl className="grid grid-cols-1 gap-3 border-b border-navy/15 pb-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                <DetailValue
                    className="sm:col-span-2 lg:col-span-1 xl:col-span-2"
                    label="Penanggung jawab"
                    value={request.handler_name ?? 'Belum ditetapkan'}
                />
                <DetailValue
                    label="Waktu masuk"
                    value={dateTime(request.submitted_at)}
                />
                <DetailValue
                    label="Respons pertama"
                    value={duration(request.response_seconds)}
                />
            </dl>
            {notice && (
                <p
                    role="alert"
                    className="rounded border border-navy/15 bg-gold/10 p-3 text-sm"
                >
                    {notice}
                </p>
            )}
            {request.completed_at && (
                <p className="text-sm">
                    Selesai: {dateTime(request.completed_at)}
                </p>
            )}
            {request.cancelled_at && (
                <div className="space-y-1 text-sm">
                    <p>Dibatalkan: {dateTime(request.cancelled_at)}</p>
                    <p className="break-words">
                        Alasan: {request.cancellation_reason}
                    </p>
                </div>
            )}
            {visitation && (
                <div className="space-y-1 rounded-md border border-navy/15 p-3 text-sm">
                    <p className="font-medium">Tautan ziarah</p>
                    <p>
                        {visitation.validation_fields.length > 0
                            ? visitationCorrectionMessage(
                                  visitation.validation_fields,
                              )
                            : visitation.state === 'ready'
                              ? 'Tautan berhasil diperoleh'
                              : visitation.state === 'failed'
                                ? 'Tautan belum berhasil diperoleh. Pesan customer ditahan.'
                                : 'Menunggu tautan sebelum pesan customer dikirim.'}
                    </p>
                    {visitation.last_error_code &&
                        visitation.validation_fields.length === 0 && (
                            <p className="text-xs text-navy/70">
                                Kode: {visitation.last_error_code}
                            </p>
                        )}
                </div>
            )}
            {notificationFailures.length > 0 && (
                <div className="space-y-3 rounded-md border border-gold/60 bg-gold/5 p-3">
                    <h3 className="text-sm font-semibold">
                        Notifikasi WhatsApp
                    </h3>
                    <ul className="divide-y divide-navy/15">
                        {notificationFailures.map((delivery) => (
                            <NotificationRetry
                                key={delivery.id}
                                delivery={delivery}
                                url={url}
                            />
                        ))}
                    </ul>
                </div>
            )}
            {!canProcess && (
                <p className="text-sm font-medium text-navy/70">
                    Akses baca saja
                </p>
            )}
            {canProcess && request.status === 'new_request' && (
                <p className="text-sm text-navy/70">
                    Data bisa diubah setelah pengajuan diklaim dan ditandai
                    sudah dihubungi.
                </p>
            )}
            {canProcess && (
                <>
                    {Object.keys(status.errors).length > 0 && (
                        <ul
                            role="alert"
                            className="space-y-1 rounded-md border border-navy/15 bg-gold/10 p-3 text-sm"
                        >
                            {Object.entries(status.errors).map(
                                ([field, message]) => {
                                    const anchor =
                                        field !== 'contact_evidence' &&
                                        field in documentLabels
                                            ? 'dokumen'
                                            : [
                                                    'has_plot',
                                                    'applicant_whatsapp',
                                                    'relationship_to_deceased',
                                                    'burial_site',
                                                    'zone',
                                                    'lot_number',
                                                    'paid_in_full',
                                                    'current_burial_at',
                                                ].includes(field)
                                              ? 'data-pengajuan'
                                              : null;
                                    return (
                                        <li key={field}>
                                            {anchor ? (
                                                <a
                                                    className={linkClass}
                                                    href={'#' + anchor}
                                                >
                                                    {validationLabels[field]}
                                                </a>
                                            ) : (
                                                validationLabels[field]
                                            )}
                                            {validationLabels[field]
                                                ? ': '
                                                : ''}
                                            {message}
                                        </li>
                                    );
                                },
                            )}
                        </ul>
                    )}
                    <form
                        onSubmit={(event) => changeStatus(event, next)}
                        className="space-y-3"
                    >
                        {next === 'contacted' && (
                            <div className="space-y-2">
                                <p className="text-sm font-medium">
                                    Foto bukti kontak (wajib)
                                </p>
                                <FilePicker
                                    label="Pilih foto bukti kontak"
                                    accept=".jpg,.jpeg,.png,.webp"
                                    file={status.data.contact_evidence}
                                    disabled={status.processing}
                                    onSelect={(file) => {
                                        if (file.size > 5 * 1024 * 1024) {
                                            status.setError(
                                                'contact_evidence',
                                                'Ukuran foto melebihi 5 MiB.',
                                            );
                                            return;
                                        }
                                        status.clearErrors('contact_evidence');
                                        status.setData(
                                            'contact_evidence',
                                            file,
                                        );
                                    }}
                                />
                            </div>
                        )}
                        <button
                            className={
                                buttonClass +
                                ' w-full justify-center text-center'
                            }
                            disabled={status.processing}
                        >
                            {next === 'contacted'
                                ? 'Klaim dan tandai sudah dihubungi'
                                : next === 'in_progress'
                                  ? 'Mulai proses'
                                  : 'Selesaikan pengajuan'}
                        </button>
                    </form>
                    {canTransfer && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                transfer.post(url + '/penanggung-jawab', {
                                    preserveScroll: true,
                                    onSuccess: () => transfer.reset(),
                                });
                            }}
                            className="space-y-2 border-t border-navy/15 pt-3"
                        >
                            <label className="block text-sm font-medium">
                                Alihkan penanggung jawab
                                <select
                                    className={fieldClass + ' mt-1'}
                                    required
                                    value={transfer.data.recipient_id}
                                    onChange={(event) =>
                                        transfer.setData(
                                            'recipient_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Pilih staf aktif</option>
                                    {handlers
                                        .filter(
                                            (handler) =>
                                                handler.id !==
                                                request.responsible_user_id,
                                        )
                                        .map((handler) => (
                                            <option
                                                key={handler.id}
                                                value={handler.id}
                                            >
                                                {handler.name} ({handler.role})
                                            </option>
                                        ))}
                                </select>
                            </label>
                            {transfer.errors.recipient_id && (
                                <p
                                    role="alert"
                                    className="text-sm text-red-900"
                                >
                                    {transfer.errors.recipient_id}
                                </p>
                            )}
                            <button
                                className={linkClass + ' min-h-9 text-sm'}
                                disabled={transfer.processing}
                            >
                                Alihkan
                            </button>
                        </form>
                    )}
                    <details className="border-t border-navy/15 pt-3">
                        <summary className="cursor-pointer text-sm font-medium text-red-800">
                            Batalkan pengajuan
                        </summary>
                        <form
                            onSubmit={(event) =>
                                changeStatus(event, 'cancelled')
                            }
                            className="mt-3 space-y-2"
                        >
                            <label className="block text-sm font-medium">
                                Alasan pembatalan
                                <textarea
                                    className={fieldClass + ' mt-1'}
                                    required
                                    maxLength={2000}
                                    value={status.data.reason}
                                    onChange={(event) =>
                                        status.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <button
                                className="min-h-10 rounded-md border border-red-900/30 px-3 text-sm font-medium text-red-800 hover:bg-red-50"
                                disabled={status.processing}
                            >
                                Konfirmasi pembatalan
                            </button>
                        </form>
                    </details>
                </>
            )}
        </section>
    );
}

function ActivityTimeline({
    activity,
    url,
}: {
    activity: Activity;
    url: string;
}) {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    function loadMore() {
        setLoading(true);
        setFailed(false);
        router.get(
            url,
            { activity_cursor: activity.next_cursor },
            {
                only: ['activity'],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onFinish: () => setLoading(false),
                onNetworkError: () => {
                    setFailed(true);
                    return false;
                },
            },
        );
    }

    return (
        <section
            id="riwayat"
            className="order-4 min-w-0 space-y-3 rounded-lg border border-navy/15 bg-white p-4 sm:p-5"
        >
            <h2 className="flex items-center gap-2 text-lg font-semibold">
                <History className="size-5" aria-hidden="true" /> Riwayat
                aktivitas
            </h2>
            <ol className="ml-1.5 border-l border-navy/15">
                {activity.data.map((entry) => {
                    const document = entry.document_type
                        ? documentLabels[entry.document_type]
                        : null;
                    const action =
                        document && entry.action === 'document_added'
                            ? document + ' diunggah'
                            : document && entry.action === 'document_accessed'
                              ? document + ' dilihat'
                              : (activityLabels[entry.action] ?? entry.action);
                    return (
                        <li
                            key={entry.id}
                            className="relative min-w-0 pb-3 pl-4 last:pb-0"
                        >
                            <span
                                className="absolute top-1.5 -left-1 size-2 rounded-full bg-navy"
                                aria-hidden="true"
                            />
                            <p className="text-sm font-medium break-words">
                                {action}
                            </p>
                            <p className="text-sm break-words text-navy/75">
                                {entry.actor}
                            </p>
                            <p className="text-xs text-navy/65">
                                {dateTime(entry.occurred_at)}
                            </p>
                        </li>
                    );
                })}
            </ol>
            {failed && (
                <p role="alert" className="text-sm text-red-900">
                    Riwayat tambahan gagal dimuat. Coba lagi.
                </p>
            )}
            {activity.next_cursor && (
                <button
                    type="button"
                    onClick={loadMore}
                    disabled={loading}
                    className={linkClass + ' min-h-10 text-sm'}
                >
                    {loading ? 'Memuat riwayat…' : 'Tampilkan lebih banyak'}
                </button>
            )}
        </section>
    );
}

export default function StaffRequest(props: Props) {
    const { request, canEdit, documents, activity } = props;
    const url = '/staf/pengajuan/' + request.id;
    const lightBadge = ['new_request', 'contacted', 'cancelled'].includes(
        request.status,
    );
    return (
        <StaffLayout>
            <main className="mx-auto max-w-[1440px] space-y-4 p-4 sm:p-6 xl:p-8">
                <Head title={request.reference_code} />
                <Link
                    className={
                        linkClass + ' inline-flex items-center gap-2 text-sm'
                    }
                    href="/staf/pengajuan"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" /> Kembali
                    ke pengajuan
                </Link>
                <header className="space-y-1 border-b border-navy/15 pb-4">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1 className="text-2xl font-semibold break-all sm:text-3xl">
                            {request.reference_code}
                        </h1>
                        <span
                            className={
                                'rounded-full px-3 py-1 text-sm font-semibold ' +
                                statusColors[request.status] +
                                (lightBadge ? ' text-navy' : ' text-white')
                            }
                        >
                            {statuses[request.status]}
                        </span>
                    </div>
                    <p className="text-lg break-words text-navy/75">
                        {request.deceased_name}
                    </p>
                </header>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,13fr)_minmax(0,7fr)]">
                    <div className="contents lg:block lg:min-w-0 lg:space-y-4">
                        <StaffRequestData
                            key={request.id}
                            record={request}
                            canEdit={canEdit}
                            url={url}
                        />
                        <StaffRequestDocuments
                            key={request.id}
                            documents={documents}
                            canEdit={canEdit}
                            url={url}
                        />
                    </div>
                    <div className="contents lg:sticky lg:top-4 lg:block lg:max-h-[calc(100dvh-2rem)] lg:min-w-0 lg:space-y-4 lg:self-start lg:overflow-y-auto">
                        <StatusPanel
                            key={request.status}
                            {...props}
                            url={url}
                        />
                        <ActivityTimeline activity={activity} url={url} />
                    </div>
                </div>
            </main>
        </StaffLayout>
    );
}
