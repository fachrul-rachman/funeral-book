import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleCheck,
    FileText,
    LogIn,
    Send,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { buttonClass, fieldClass, linkClass } from '../lib/funeral';
import { phoneCharacters, validApplicantPhone } from '../lib/phone';

type Receipt = { reference_code: string; message: string };
type Props = {
    siteKey: string | null;
    privacyUrl: string | null;
    consentText: string;
    receipt?: Receipt;
};
type Turnstile = {
    render: (
        container: HTMLElement,
        options: Record<string, unknown>,
    ) => string;
    remove: (id: string) => void;
    reset: (id: string) => void;
};

const labels = [
    'Data pemohon',
    'Data almarhum/almarhumah',
    'Informasi pemakaman',
    'Dokumen dan persetujuan',
];
const documentLabels = {
    ownership_certificate: 'Sertifikat kepemilikan makam',
    plot_owner_ktp: 'KTP pemilik makam',
    deceased_ktp: 'KTP almarhum/almarhumah',
    hospital_death_certificate: 'Surat kematian dari rumah sakit',
    embassy_death_certificate: 'Surat kematian dari kedutaan (untuk WNA)',
};
const shortLabels = ['Pemohon', 'Almarhum', 'Makam', 'Dokumen'];
const relationships = [
    'Orang tua',
    'Suami/Istri',
    'Anak',
    'Saudara kandung',
    'Teman',
    'Lainnya',
];
const formFieldClass = fieldClass + ' mt-1.5 h-11 text-base font-normal';
const formLabelClass = 'block min-w-0 text-sm font-medium';

function turnstile() {
    return (window as Window & { turnstile?: Turnstile }).turnstile;
}

function newSubmissionKey() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

export default function FuneralForm({
    siteKey,
    privacyUrl,
    consentText,
    receipt,
}: Props) {
    const [step, setStep] = useState(0);
    const [challengeError, setChallengeError] = useState('');
    const [networkError, setNetworkError] = useState('');
    const [token, setToken] = useState('');
    const heading = useRef<HTMLHeadingElement>(null);
    const errors = useRef<HTMLDivElement>(null);
    const widget = useRef<HTMLDivElement>(null);
    const widgetId = useRef<string | null>(null);
    const key = useRef<string | null>(null);
    const form = useForm({
        applicant_name: '',
        applicant_whatsapp: '',
        relationship_to_deceased: '',
        relationship_other: '',
        deceased_name: '',
        deceased_date_of_death: '',
        funeral_home_location: '',
        has_plot: '',
        burial_site: '',
        zone: '',
        lot_number: '',
        paid_in_full: '',
        estimated_burial_date: '',
        estimated_burial_time: '',
        consent_accepted: false,
        website: '',
        documents: {} as Partial<Record<keyof typeof documentLabels, File>>,
    });

    useEffect(() => {
        if (step !== 3 || !siteKey || receipt) return;
        const render = () => {
            if (!widget.current || widgetId.current !== null) return;
            const api = turnstile();
            if (!api) return;
            setChallengeError('');
            widgetId.current = api.render(widget.current, {
                sitekey: siteKey,
                action: 'funeral_submit',
                size: widget.current.clientWidth < 300 ? 'compact' : 'normal',
                callback: (value: string) => {
                    setChallengeError('');
                    setToken(value);
                },
                'expired-callback': () => setToken(''),
                'error-callback': () => {
                    setToken('');
                    setChallengeError('Verifikasi gagal dimuat. Coba lagi.');
                },
            });
        };
        const fail = () =>
            setChallengeError(
                'Verifikasi gagal dimuat. Muat ulang halaman untuk mencoba lagi.',
            );
        let script =
            document.querySelector<HTMLScriptElement>('#funeral-turnstile');
        if (!script) {
            script = document.createElement('script');
            script.id = 'funeral-turnstile';
            script.src =
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            document.head.append(script);
        }
        script.addEventListener('load', render);
        script.addEventListener('error', fail);
        render();
        return () => {
            script.removeEventListener('load', render);
            script.removeEventListener('error', fail);
            if (widgetId.current !== null)
                turnstile()?.remove(widgetId.current);
            widgetId.current = null;
        };
    }, [step, siteKey, receipt]);

    function update<K extends keyof typeof form.data>(
        field: K,
        value: (typeof form.data)[K],
    ) {
        key.current = null;
        form.setData((previous) => ({ ...previous, [field]: value }));
    }

    function move(next: number) {
        setToken('');
        setStep(next);
        requestAnimationFrame(() => heading.current?.focus());
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (step === 0) {
            if (!validApplicantPhone(form.data.applicant_whatsapp)) {
                form.setError(
                    'applicant_whatsapp',
                    'Gunakan nomor WhatsApp Indonesia berawalan 08, 62, atau +62 dengan 10 sampai 13 digit angka.',
                );
                event.currentTarget
                    .querySelector<HTMLInputElement>(
                        '[name="applicant_whatsapp"]',
                    )
                    ?.focus();
                return;
            }
            form.clearErrors('applicant_whatsapp');
        }
        if (step === 2) {
            const estimate = Date.parse(
                form.data.estimated_burial_date +
                    'T' +
                    form.data.estimated_burial_time +
                    ':00+07:00',
            );
            if (!Number.isFinite(estimate) || estimate <= Date.now()) {
                form.setError(
                    'estimated_burial_time',
                    'Pilih waktu pemakaman yang masih akan datang dalam WIB.',
                );
                event.currentTarget
                    .querySelector<HTMLInputElement>(
                        '[name="estimated_burial_time"]',
                    )
                    ?.focus();
                return;
            }
            form.clearErrors('estimated_burial_time');
        }
        if (step < 3) {
            move(step + 1);
            return;
        }
        if (!token && !key.current) {
            setChallengeError(
                siteKey
                    ? 'Selesaikan verifikasi keamanan sebelum mengirim. Jika verifikasi tidak muncul, periksa koneksi lalu muat ulang halaman.'
                    : 'Verifikasi pengajuan belum dikonfigurasi. Hubungi pengelola aplikasi.',
            );
            requestAnimationFrame(() =>
                document.getElementById('challenge-error')?.focus(),
            );
            return;
        }
        key.current ??= newSubmissionKey();
        setNetworkError('');
        const { relationship_other, ...fields } = form.data;
        const data = {
            ...fields,
            relationship_to_deceased:
                fields.relationship_to_deceased === 'Lainnya'
                    ? relationship_other.trim()
                    : fields.relationship_to_deceased,
            submission_key: key.current,
            turnstile_token: token,
            documents: Object.fromEntries(
                Object.entries(form.data.documents).filter(([type]) =>
                    visibleDocument(type),
                ),
            ),
        };
        form.transform(() => data);
        form.post('/pengajuan', {
            forceFormData: true,
            preserveScroll: true,
            onError: () => requestAnimationFrame(() => errors.current?.focus()),
            onNetworkError: () => {
                setNetworkError(
                    'Koneksi terputus. Kirim ulang untuk memeriksa apakah pengajuan sudah diterima.',
                );
                return false;
            },
            onFinish: () => {
                setToken('');
                if (widgetId.current !== null)
                    turnstile()?.reset(widgetId.current);
            },
        });
    }

    function visibleDocument(type: string) {
        if (type === 'ownership_certificate')
            return form.data.has_plot === '1' && form.data.paid_in_full === '1';
        if (type === 'plot_owner_ktp') return form.data.has_plot === '1';
        return true;
    }

    function input(
        field: Exclude<
            keyof typeof form.data,
            'documents' | 'consent_accepted'
        >,
        label: string,
        type = 'text',
        required = true,
    ) {
        const isPhone = field === 'applicant_whatsapp';
        return (
            <label className={formLabelClass}>
                {label}
                {required && <span aria-hidden="true"> *</span>}
                <input
                    className={formFieldClass}
                    type={type}
                    required={required}
                    name={field}
                    pattern={
                        field === 'relationship_other'
                            ? String.raw`.*\S.*`
                            : undefined
                    }
                    autoComplete={isPhone ? 'tel' : undefined}
                    maxLength={
                        isPhone
                            ? 40
                            : field === 'relationship_other'
                              ? 100
                              : 255
                    }
                    min={
                        field === 'estimated_burial_date'
                            ? form.data.deceased_date_of_death
                            : undefined
                    }
                    aria-invalid={isPhone && !!form.errors.applicant_whatsapp}
                    aria-describedby={
                        isPhone && form.errors.applicant_whatsapp
                            ? 'applicant-phone-error'
                            : undefined
                    }
                    value={form.data[field]}
                    onChange={(event) => {
                        const value = event.target.value;
                        if (isPhone) {
                            if (!phoneCharacters(value)) {
                                form.setError(
                                    'applicant_whatsapp',
                                    'Gunakan angka, dengan +62 atau 08 untuk nomor Indonesia.',
                                );
                                return;
                            }
                            form.clearErrors('applicant_whatsapp');
                        }
                        update(field, value);
                    }}
                />
                {isPhone && form.errors.applicant_whatsapp && (
                    <span
                        id="applicant-phone-error"
                        role="alert"
                        className="text-sm text-navy"
                    >
                        {form.errors.applicant_whatsapp}
                    </span>
                )}
            </label>
        );
    }

    const messages = [
        ...new Set(
            [
                ...Object.entries(form.errors)
                    .filter(
                        ([field]) =>
                            step !== 0 || field !== 'applicant_whatsapp',
                    )
                    .map(([, message]) => message),
                networkError,
            ].filter(Boolean),
        ),
    ];
    return (
        <main className="min-h-dvh bg-[#FAF8F3]">
            <Head title="Pengajuan layanan pemakaman" />
            <header className="flex flex-wrap items-center justify-between gap-3 border-t-4 border-b border-gold border-b-navy/15 bg-white px-4 py-4 sm:px-8 xl:px-12">
                <p className="font-semibold tracking-wide">
                    Lestari Memorial Park
                </p>
                <Link
                    href="/masuk"
                    className={
                        linkClass + ' inline-flex items-center gap-2 text-sm'
                    }
                >
                    <LogIn className="size-4" aria-hidden="true" /> Masuk staf
                </Link>
            </header>
            <div className="mx-auto w-full max-w-[912px] px-4 pt-7 pb-12 sm:pt-10 sm:pb-20">
                <h1 className="text-center text-2xl font-semibold sm:text-3xl">
                    Pengajuan layanan pemakaman
                </h1>
                <div className="mt-5 rounded-xl border border-navy/15 bg-white p-4 sm:mt-7 sm:p-10 sm:shadow-[0_10px_30px_#2433590d] lg:p-12">
                    {!receipt && (
                        <nav
                            aria-label="Langkah pengajuan"
                            className="mb-6 sm:mb-8"
                        >
                            <ol className="grid grid-cols-4 gap-1">
                                {labels.map((label, index) => (
                                    <li
                                        key={label}
                                        aria-current={
                                            index === step ? 'step' : undefined
                                        }
                                        className="relative flex min-w-0 flex-col items-center gap-2 text-center"
                                    >
                                        {index < labels.length - 1 && (
                                            <span
                                                aria-hidden="true"
                                                className={
                                                    'absolute top-4 left-1/2 h-px w-full ' +
                                                    (index < step
                                                        ? 'bg-gold'
                                                        : 'bg-navy/20')
                                                }
                                            />
                                        )}
                                        <span
                                            className={
                                                'relative flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-semibold ' +
                                                (index === step
                                                    ? 'border-navy bg-navy text-white'
                                                    : index < step
                                                      ? 'border-gold bg-gold text-navy'
                                                      : 'border-navy/30 bg-white text-navy')
                                            }
                                        >
                                            {index < step ? (
                                                <>
                                                    <Check
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    <span className="sr-only">
                                                        Selesai
                                                    </span>
                                                </>
                                            ) : (
                                                index + 1
                                            )}
                                        </span>
                                        <span
                                            className={
                                                'text-xs ' +
                                                (index === step
                                                    ? 'font-semibold'
                                                    : 'text-navy/75')
                                            }
                                        >
                                            <span className="sm:hidden">
                                                {shortLabels[index]}
                                            </span>
                                            <span className="hidden sm:inline">
                                                {label}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </nav>
                    )}
                    <section className="min-w-0">
                        {receipt ? (
                            <div
                                role="status"
                                className="space-y-3 rounded-lg border border-gold p-5"
                            >
                                <CircleCheck
                                    className="size-8"
                                    aria-hidden="true"
                                />
                                <h2 className="text-xl font-semibold">
                                    Pengajuan diterima
                                </h2>
                                <p>{receipt.message}</p>
                                <p>
                                    Nomor referensi{' '}
                                    <strong className="break-all">
                                        {receipt.reference_code}
                                    </strong>
                                </p>
                                <a href="/" className="underline">
                                    Buat pengajuan baru
                                </a>
                            </div>
                        ) : (
                            <form className="space-y-5" onSubmit={submit}>
                                <h2
                                    ref={heading}
                                    tabIndex={-1}
                                    className="border-b border-b-gold pb-3 text-xl font-semibold sm:text-2xl"
                                >
                                    Langkah {step + 1} dari 4: {labels[step]}
                                </h2>
                                {messages.length > 0 && (
                                    <div
                                        ref={errors}
                                        tabIndex={-1}
                                        role="alert"
                                        className="rounded border border-gold bg-gold/10 p-3 text-navy"
                                    >
                                        <p className="font-semibold">
                                            Periksa pengajuan sebelum mengirim
                                            ulang.
                                        </p>
                                        <ul className="list-disc pl-5">
                                            {messages.map((message) => (
                                                <li key={message}>{message}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                <div aria-hidden="true" className="hidden">
                                    <input
                                        tabIndex={-1}
                                        autoComplete="off"
                                        name="website"
                                        value={form.data.website}
                                        onChange={(event) =>
                                            update(
                                                'website',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                {step === 0 && (
                                    <div className="grid gap-4 sm:grid-cols-2 sm:gap-5">
                                        {input(
                                            'applicant_name',
                                            'Nama lengkap pemohon',
                                        )}
                                        {input(
                                            'applicant_whatsapp',
                                            'Nomor WhatsApp pemohon',
                                            'tel',
                                        )}
                                        <label
                                            className={
                                                formLabelClass +
                                                ' sm:col-span-2'
                                            }
                                        >
                                            Hubungan dengan almarhum/almarhumah{' '}
                                            <span aria-hidden="true">*</span>
                                            <select
                                                name="relationship_to_deceased"
                                                required
                                                className={formFieldClass}
                                                value={
                                                    form.data
                                                        .relationship_to_deceased
                                                }
                                                onChange={(event) =>
                                                    update(
                                                        'relationship_to_deceased',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="" disabled>
                                                    Pilih hubungan
                                                </option>
                                                {relationships.map(
                                                    (relationship) => (
                                                        <option
                                                            key={relationship}
                                                        >
                                                            {relationship}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </label>
                                        {form.data.relationship_to_deceased ===
                                            'Lainnya' && (
                                            <div className="sm:col-span-2">
                                                {input(
                                                    'relationship_other',
                                                    'Sebutkan hubungan',
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                                {step === 1 && (
                                    <div className="grid gap-4 sm:grid-cols-2 sm:gap-5">
                                        {input(
                                            'deceased_name',
                                            'Nama lengkap almarhum/almarhumah',
                                        )}
                                        {input(
                                            'deceased_date_of_death',
                                            'Tanggal meninggal',
                                            'date',
                                        )}
                                        <div className="sm:col-span-2">
                                            {input(
                                                'funeral_home_location',
                                                'Lokasi rumah duka',
                                            )}
                                        </div>
                                    </div>
                                )}
                                {step === 2 && (
                                    <div className="grid gap-4 sm:grid-cols-2 sm:gap-5">
                                        <label
                                            className={
                                                formLabelClass +
                                                ' sm:col-span-2'
                                            }
                                        >
                                            Sudah memiliki lahan makam? *
                                            <select
                                                required
                                                className={formFieldClass}
                                                value={form.data.has_plot}
                                                onChange={(event) =>
                                                    update(
                                                        'has_plot',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Pilih jawaban
                                                </option>
                                                <option value="1">Ya</option>
                                                <option value="0">Tidak</option>
                                            </select>
                                        </label>
                                        {form.data.has_plot === '1' && (
                                            <>
                                                <label
                                                    className={formLabelClass}
                                                >
                                                    Lokasi makam
                                                    <select
                                                        className={
                                                            formFieldClass
                                                        }
                                                        value={
                                                            form.data
                                                                .burial_site
                                                        }
                                                        onChange={(event) =>
                                                            update(
                                                                'burial_site',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            Belum diketahui
                                                        </option>
                                                        <option value="karawang">
                                                            Karawang
                                                        </option>
                                                        <option value="tangerang">
                                                            Tangerang
                                                        </option>
                                                    </select>
                                                </label>
                                                {input(
                                                    'zone',
                                                    'Zona',
                                                    'text',
                                                    false,
                                                )}
                                                {input(
                                                    'lot_number',
                                                    'Nomor lahan',
                                                    'text',
                                                    false,
                                                )}
                                                <label
                                                    className={formLabelClass}
                                                >
                                                    Pembayaran sudah lunas?
                                                    <select
                                                        className={
                                                            formFieldClass
                                                        }
                                                        value={
                                                            form.data
                                                                .paid_in_full
                                                        }
                                                        onChange={(event) =>
                                                            update(
                                                                'paid_in_full',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            Belum diketahui
                                                        </option>
                                                        <option value="1">
                                                            Ya
                                                        </option>
                                                        <option value="0">
                                                            Tidak
                                                        </option>
                                                    </select>
                                                </label>
                                            </>
                                        )}
                                        {input(
                                            'estimated_burial_date',
                                            'Perkiraan tanggal pemakaman',
                                            'date',
                                        )}
                                        {input(
                                            'estimated_burial_time',
                                            'Perkiraan waktu pemakaman (WIB)',
                                            'time',
                                        )}
                                    </div>
                                )}
                                {step === 3 && (
                                    <div className="space-y-6">
                                        <section
                                            aria-labelledby="documents-heading"
                                            className="space-y-4"
                                        >
                                            <header className="space-y-1">
                                                <h3
                                                    id="documents-heading"
                                                    className="text-lg font-semibold"
                                                >
                                                    Dokumen pendukung{' '}
                                                    <span className="text-sm font-normal text-navy/75">
                                                        (opsional)
                                                    </span>
                                                </h3>
                                                <p
                                                    id="document-instructions"
                                                    className="text-sm leading-relaxed text-navy/75"
                                                >
                                                    JPEG, PNG, WebP atau PDF,
                                                    maksimal 5 MiB per berkas.
                                                    Dokumen yang belum tersedia
                                                    bisa dilengkapi bersama tim
                                                    kami setelah pengajuan
                                                    dikirim.
                                                </p>
                                            </header>
                                            <ul className="divide-y divide-navy/15 rounded-lg border border-navy/20">
                                                {Object.entries(documentLabels)
                                                    .filter(([type]) =>
                                                        visibleDocument(type),
                                                    )
                                                    .map(([type, label]) => {
                                                        const field =
                                                            type as keyof typeof documentLabels;
                                                        const selected =
                                                            form.data.documents[
                                                                field
                                                            ];
                                                        return (
                                                            <li
                                                                key={field}
                                                                className="space-y-2 p-3 sm:p-4"
                                                            >
                                                                <p
                                                                    id={
                                                                        field +
                                                                        '-label'
                                                                    }
                                                                    className="font-medium"
                                                                >
                                                                    {label}
                                                                </p>
                                                                {selected && (
                                                                    <div
                                                                        role="status"
                                                                        className="flex min-w-0 items-start gap-2 rounded-md bg-navy/5 px-3 py-2 text-sm"
                                                                    >
                                                                        <FileText
                                                                            className="mt-0.5 size-4 shrink-0"
                                                                            aria-hidden="true"
                                                                        />
                                                                        <div className="min-w-0">
                                                                            <p className="text-xs text-navy/75">
                                                                                Dipilih
                                                                                (belum
                                                                                diunggah)
                                                                            </p>
                                                                            <p className="break-all">
                                                                                {
                                                                                    selected.name
                                                                                }
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                )}
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <label className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-navy/40 px-3 text-sm font-medium focus-within:outline-2 focus-within:outline-offset-2">
                                                                        <Upload
                                                                            className="size-4"
                                                                            aria-hidden="true"
                                                                        />
                                                                        <span
                                                                            id={
                                                                                field +
                                                                                '-action'
                                                                            }
                                                                        >
                                                                            {selected
                                                                                ? 'Ganti berkas'
                                                                                : 'Pilih berkas'}
                                                                        </span>
                                                                        <input
                                                                            type="file"
                                                                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                                                                            className="sr-only"
                                                                            disabled={
                                                                                form.processing
                                                                            }
                                                                            aria-labelledby={
                                                                                field +
                                                                                '-action ' +
                                                                                field +
                                                                                '-label'
                                                                            }
                                                                            aria-describedby="document-instructions"
                                                                            onChange={(
                                                                                event,
                                                                            ) => {
                                                                                const file =
                                                                                    event
                                                                                        .currentTarget
                                                                                        .files?.[0];
                                                                                if (
                                                                                    file
                                                                                )
                                                                                    update(
                                                                                        'documents',
                                                                                        {
                                                                                            ...form
                                                                                                .data
                                                                                                .documents,
                                                                                            [field]:
                                                                                                file,
                                                                                        },
                                                                                    );
                                                                                event.currentTarget.value =
                                                                                    '';
                                                                            }}
                                                                        />
                                                                    </label>
                                                                    {selected && (
                                                                        <button
                                                                            type="button"
                                                                            disabled={
                                                                                form.processing
                                                                            }
                                                                            className="inline-flex min-h-11 items-center gap-1 px-2 text-sm underline underline-offset-4"
                                                                            aria-label={
                                                                                'Hapus berkas ' +
                                                                                label
                                                                            }
                                                                            onClick={() => {
                                                                                const documents =
                                                                                    {
                                                                                        ...form
                                                                                            .data
                                                                                            .documents,
                                                                                    };
                                                                                delete documents[
                                                                                    field
                                                                                ];
                                                                                update(
                                                                                    'documents',
                                                                                    documents,
                                                                                );
                                                                            }}
                                                                        >
                                                                            <X
                                                                                className="size-4"
                                                                                aria-hidden="true"
                                                                            />{' '}
                                                                            Hapus
                                                                        </button>
                                                                    )}
                                                                </div>
                                                            </li>
                                                        );
                                                    })}
                                            </ul>
                                        </section>
                                        <section
                                            aria-labelledby="consent-heading"
                                            className="space-y-3 rounded-lg border border-gold bg-gold/10 p-4"
                                        >
                                            <h3
                                                id="consent-heading"
                                                className="text-lg font-semibold"
                                            >
                                                Persetujuan penggunaan data
                                            </h3>
                                            <label className="flex items-start gap-3 text-sm leading-relaxed">
                                                <input
                                                    className="mt-1 size-4 shrink-0"
                                                    type="checkbox"
                                                    required
                                                    checked={
                                                        form.data
                                                            .consent_accepted
                                                    }
                                                    onChange={(event) =>
                                                        update(
                                                            'consent_accepted',
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                                <span>{consentText}</span>
                                            </label>
                                            {privacyUrl && (
                                                <a
                                                    className={
                                                        linkClass + ' text-sm'
                                                    }
                                                    href={privacyUrl}
                                                >
                                                    Pemberitahuan privasi
                                                </a>
                                            )}
                                        </section>
                                        <section
                                            aria-labelledby="security-heading"
                                            className="space-y-2"
                                        >
                                            <h3
                                                id="security-heading"
                                                className="font-semibold"
                                            >
                                                Verifikasi keamanan
                                            </h3>
                                            {siteKey ? (
                                                <div
                                                    ref={widget}
                                                    className="min-w-0"
                                                />
                                            ) : (
                                                <p
                                                    role="status"
                                                    className="text-sm"
                                                >
                                                    Verifikasi pengajuan belum
                                                    dikonfigurasi.
                                                </p>
                                            )}
                                            {challengeError && (
                                                <p
                                                    id="challenge-error"
                                                    tabIndex={-1}
                                                    role="alert"
                                                    className="text-sm"
                                                >
                                                    {challengeError}
                                                </p>
                                            )}
                                        </section>
                                    </div>
                                )}
                                <div className="flex flex-col-reverse gap-3 border-t border-navy/15 pt-4 sm:flex-row">
                                    {step > 0 && (
                                        <button
                                            type="button"
                                            disabled={form.processing}
                                            onClick={() => move(step - 1)}
                                            className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md border border-navy px-4 py-2.5 sm:w-auto"
                                        >
                                            <ArrowLeft
                                                className="size-4"
                                                aria-hidden="true"
                                            />{' '}
                                            Kembali
                                        </button>
                                    )}
                                    <button
                                        className={
                                            buttonClass +
                                            ' w-full justify-center sm:ml-auto sm:w-auto'
                                        }
                                        disabled={form.processing}
                                    >
                                        {step < 3
                                            ? 'Lanjut'
                                            : form.processing
                                              ? 'Mengirim…'
                                              : 'Kirim pengajuan'}
                                        {step < 3 ? (
                                            <ArrowRight
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Send
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </button>
                                </div>
                            </form>
                        )}
                    </section>
                </div>
            </div>
        </main>
    );
}
