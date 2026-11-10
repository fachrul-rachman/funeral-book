import { Head, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleAlert,
    CircleCheck,
    Copy,
    FileText,
    Send,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    buttonClass,
    fieldClass,
    helpWhatsApp,
    invalidFieldClass,
    linkClass,
} from '../lib/funeral';
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
type Aria = {
    id: string;
    className: string;
    'aria-invalid'?: true;
    'aria-describedby'?: string;
};

const labels = [
    'Tentang Anda',
    'Tentang almarhum/almarhumah',
    'Rencana pemakaman',
    'Dokumen dan persetujuan',
];
const shortLabels = ['Anda', 'Almarhum', 'Pemakaman', 'Dokumen'];
const documentLabels = {
    ownership_certificate: 'Sertifikat kepemilikan makam',
    plot_owner_ktp: 'KTP pemilik makam',
    deceased_ktp: 'KTP almarhum/almarhumah',
    hospital_death_certificate: 'Surat kematian dari rumah sakit',
    embassy_death_certificate: 'Surat kematian dari kedutaan (untuk WNA)',
};
const relationships = [
    'Orang tua',
    'Suami/Istri',
    'Anak',
    'Saudara kandung',
    'Teman',
    'Lainnya',
];
const text = {
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
    website: '',
};
const initial = {
    ...text,
    consent_accepted: false,
    documents: {} as Partial<Record<keyof typeof documentLabels, File>>,
};
type Name = keyof typeof text;
type AnyName = keyof typeof initial;
type Errors = Partial<Record<AnyName, string>>;

// Fields rendered inline on each step. Other server errors go to the summary.
const stepFields: string[][] = [
    ['applicant_name', 'applicant_whatsapp', 'relationship_to_deceased'],
    ['deceased_name', 'deceased_date_of_death', 'funeral_home_location'],
    [
        'has_plot',
        'burial_site',
        'zone',
        'lot_number',
        'paid_in_full',
        'estimated_burial_date',
        'estimated_burial_time',
    ],
    ['consent_accepted'],
];
const draftKey = 'funeral-form-draft';
const draftNames = (Object.keys(text) as Name[]).filter(
    (name) => name !== 'website',
);
const controlClass = (invalid: boolean) =>
    (invalid ? invalidFieldClass : fieldClass) +
    ' mt-1.5 h-11 text-base font-normal';

// Draft lives in sessionStorage only: it is cleared when the tab closes.
// Files, consent and the security token are never stored.
function loadDraft(): { step: number; data: Partial<typeof text> } | null {
    try {
        const saved = JSON.parse(sessionStorage.getItem(draftKey) ?? 'null');
        if (!saved || typeof saved !== 'object') return null;
        const data: Partial<typeof text> = {};
        for (const name of draftNames)
            if (typeof saved.data?.[name] === 'string')
                data[name] = saved.data[name];
        const step = Number.isInteger(saved.step)
            ? Math.min(Math.max(saved.step, 0), 3)
            : 0;
        return step === 0 && !Object.values(data).some(Boolean)
            ? null
            : { step, data };
    } catch {
        return null;
    }
}

function clearDraft() {
    try {
        sessionStorage.removeItem(draftKey);
    } catch {
        // Storage can be blocked; the form still works without it.
    }
}

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

function ErrorText({ id, children }: { id?: string; children: ReactNode }) {
    return (
        <p
            id={id}
            className="mt-1.5 flex items-start gap-1.5 text-sm font-medium text-danger"
        >
            <CircleAlert
                className="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <span>{children}</span>
        </p>
    );
}

function Field({
    name,
    label,
    required,
    optional,
    hint,
    error,
    className = '',
    children,
}: {
    name: string;
    label: string;
    required?: boolean;
    optional?: boolean;
    hint?: string;
    error?: string;
    className?: string;
    children: (aria: Aria) => ReactNode;
}) {
    const id = 'field-' + name;
    const describedBy =
        [hint && id + '-hint', error && id + '-error']
            .filter(Boolean)
            .join(' ') || undefined;
    return (
        <div className={'min-w-0 ' + className}>
            <label htmlFor={id} className="block text-base font-medium">
                {label}
                {required && <span aria-hidden="true"> *</span>}
                {optional && (
                    <span className="font-normal text-navy/75">
                        {' '}
                        (opsional)
                    </span>
                )}
            </label>
            {hint && (
                <p id={id + '-hint'} className="mt-0.5 text-sm text-navy/75">
                    {hint}
                </p>
            )}
            {children({
                id,
                className: controlClass(!!error),
                'aria-invalid': error ? true : undefined,
                'aria-describedby': describedBy,
            })}
            {error && <ErrorText id={id + '-error'}>{error}</ErrorText>}
        </div>
    );
}

function ReceiptCard({ receipt }: { receipt: Receipt }) {
    const [copied, setCopied] = useState(false);
    async function copy() {
        try {
            await navigator.clipboard.writeText(receipt.reference_code);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }
    return (
        <div
            role="status"
            className="space-y-4 rounded-lg border border-gold p-5"
        >
            <CircleCheck className="size-8" aria-hidden="true" />
            <h2 className="text-xl font-semibold">Pengajuan diterima</h2>
            <p>{receipt.message}</p>
            <div>
                <p className="text-sm text-navy/75">Nomor referensi</p>
                <p className="text-lg font-semibold break-all">
                    {receipt.reference_code}
                </p>
                {typeof navigator !== 'undefined' && navigator.clipboard && (
                    <button
                        type="button"
                        onClick={copy}
                        className="mt-2 inline-flex min-h-11 items-center gap-2 rounded-md border border-navy/40 px-3 text-sm font-medium"
                    >
                        {copied ? (
                            <Check className="size-4" aria-hidden="true" />
                        ) : (
                            <Copy className="size-4" aria-hidden="true" />
                        )}
                        {copied ? 'Nomor tersalin' : 'Salin nomor'}
                    </button>
                )}
            </div>
            <a href="/" className={linkClass}>
                Buat pengajuan baru
            </a>
        </div>
    );
}

export default function FuneralForm({
    siteKey,
    privacyUrl,
    consentText,
    receipt,
}: Props) {
    const [draft] = useState(() => (receipt ? null : loadDraft()));
    const [restored, setRestored] = useState(draft !== null);
    const [step, setStep] = useState(draft?.step ?? 0);
    const [challengeError, setChallengeError] = useState('');
    const [networkError, setNetworkError] = useState('');
    const [token, setToken] = useState('');
    const heading = useRef<HTMLHeadingElement>(null);
    const errors = useRef<HTMLDivElement>(null);
    const widget = useRef<HTMLDivElement>(null);
    const widgetId = useRef<string | null>(null);
    const key = useRef<string | null>(null);
    const form = useForm({ ...initial, ...draft?.data });

    useEffect(() => {
        if (receipt) {
            clearDraft();
            return;
        }
        try {
            sessionStorage.setItem(
                draftKey,
                JSON.stringify({
                    step,
                    data: Object.fromEntries(
                        draftNames.map((name) => [name, form.data[name]]),
                    ),
                }),
            );
        } catch {
            // Storage can be blocked; the form still works without it.
        }
    }, [form.data, step, receipt]);

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
        form.clearErrors(field);
        form.setData((previous) => ({ ...previous, [field]: value }));
    }

    function move(next: number) {
        setToken('');
        setStep(next);
        requestAnimationFrame(() => heading.current?.focus());
    }

    function startOver() {
        clearDraft();
        key.current = null;
        form.setData({ ...initial });
        form.clearErrors();
        setRestored(false);
        move(0);
    }

    function validate(current: number): Errors {
        const data = form.data;
        const found: Errors = {};
        const blank = (name: Name) => !data[name].trim();
        if (current === 0) {
            if (blank('applicant_name'))
                found.applicant_name = 'Isi nama lengkap Anda.';
            if (blank('applicant_whatsapp'))
                found.applicant_whatsapp = 'Isi nomor WhatsApp Anda.';
            else if (!validApplicantPhone(data.applicant_whatsapp))
                found.applicant_whatsapp =
                    'Gunakan nomor WhatsApp Indonesia berawalan 08, 62, atau +62 dengan 10 sampai 13 digit angka.';
            if (blank('relationship_to_deceased'))
                found.relationship_to_deceased =
                    'Pilih hubungan Anda dengan almarhum/almarhumah.';
            else if (
                data.relationship_to_deceased === 'Lainnya' &&
                blank('relationship_other')
            )
                found.relationship_other = 'Tuliskan hubungan Anda.';
        }
        if (current === 1) {
            if (blank('deceased_name'))
                found.deceased_name = 'Isi nama lengkap almarhum/almarhumah.';
            if (blank('deceased_date_of_death'))
                found.deceased_date_of_death = 'Isi tanggal meninggal.';
            if (blank('funeral_home_location'))
                found.funeral_home_location = 'Isi lokasi rumah duka.';
        }
        if (current === 2) {
            if (blank('has_plot')) found.has_plot = 'Pilih salah satu jawaban.';
            if (blank('estimated_burial_date'))
                found.estimated_burial_date =
                    'Isi perkiraan tanggal pemakaman.';
            else if (data.estimated_burial_date < data.deceased_date_of_death)
                found.estimated_burial_date =
                    'Tanggal pemakaman tidak boleh sebelum tanggal meninggal.';
            if (blank('estimated_burial_time'))
                found.estimated_burial_time = 'Isi perkiraan waktu pemakaman.';
            else if (!found.estimated_burial_date) {
                const estimate = Date.parse(
                    data.estimated_burial_date +
                        'T' +
                        data.estimated_burial_time +
                        ':00+07:00',
                );
                if (!Number.isFinite(estimate) || estimate <= Date.now())
                    found.estimated_burial_time =
                        'Pilih waktu pemakaman yang masih akan datang dalam WIB.';
            }
        }
        if (current === 3 && !data.consent_accepted)
            found.consent_accepted =
                'Centang persetujuan untuk melanjutkan pengajuan.';
        return found;
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formElement = event.currentTarget;
        const found = validate(step);
        if (Object.keys(found).length > 0) {
            form.setError(found as Record<AnyName, string>);
            requestAnimationFrame(() =>
                formElement
                    .querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus(),
            );
            return;
        }
        form.clearErrors();
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
            onError: (serverErrors) => {
                const first = stepFields
                    .slice(0, 3)
                    .findIndex((names) =>
                        names.some((name) => name in serverErrors),
                    );
                if (first !== -1) move(first);
                else requestAnimationFrame(() => errors.current?.focus());
            },
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

    function errorFor(name: Name) {
        if (name === 'relationship_other')
            return (
                form.errors.relationship_other ??
                (form.data.relationship_to_deceased === 'Lainnya'
                    ? form.errors.relationship_to_deceased
                    : undefined)
            );
        if (
            name === 'relationship_to_deceased' &&
            form.data.relationship_to_deceased === 'Lainnya'
        )
            return undefined;
        return form.errors[name];
    }

    function input(
        name: Name,
        label: string,
        type = 'text',
        options: { optional?: boolean; hint?: string; className?: string } = {},
    ) {
        const isPhone = name === 'applicant_whatsapp';
        return (
            <Field
                name={name}
                label={label}
                required={!options.optional}
                optional={options.optional}
                hint={options.hint}
                error={errorFor(name)}
                className={options.className}
            >
                {(aria) => (
                    <input
                        {...aria}
                        type={type}
                        required={!options.optional}
                        name={name}
                        autoComplete={isPhone ? 'tel' : undefined}
                        maxLength={
                            isPhone
                                ? 40
                                : name === 'relationship_other'
                                  ? 100
                                  : 255
                        }
                        min={
                            name === 'estimated_burial_date'
                                ? form.data.deceased_date_of_death || undefined
                                : undefined
                        }
                        value={form.data[name]}
                        onChange={(event) => {
                            const value = event.target.value;
                            if (isPhone && !phoneCharacters(value)) {
                                form.setError(
                                    'applicant_whatsapp',
                                    'Gunakan angka, dengan +62 atau 08 untuk nomor Indonesia.',
                                );
                                return;
                            }
                            update(name, value);
                        }}
                    />
                )}
            </Field>
        );
    }

    function choose(
        name: Name,
        label: string,
        placeholder: string,
        options: [string, string][],
        settings: { optional?: boolean; className?: string } = {},
    ) {
        return (
            <Field
                name={name}
                label={label}
                required={!settings.optional}
                optional={settings.optional}
                error={errorFor(name)}
                className={settings.className}
            >
                {(aria) => (
                    <select
                        {...aria}
                        name={name}
                        required={!settings.optional}
                        value={form.data[name]}
                        onChange={(event) => update(name, event.target.value)}
                    >
                        <option value="" disabled={!settings.optional}>
                            {placeholder}
                        </option>
                        {options.map(([value, optionLabel]) => (
                            <option key={value} value={value}>
                                {optionLabel}
                            </option>
                        ))}
                    </select>
                )}
            </Field>
        );
    }

    const inline = stepFields[step];
    const messages = [
        ...new Set(
            [
                ...Object.entries(form.errors)
                    .filter(([field]) => !inline.includes(field))
                    .map(([, message]) => message),
                networkError,
            ].filter(Boolean),
        ),
    ];
    const yesNo: [string, string][] = [
        ['1', 'Ya'],
        ['0', 'Tidak'],
    ];
    return (
        <main className="min-h-dvh bg-[#FAF8F3]">
            <Head title="Pengajuan layanan pemakaman" />
            <header className="border-t-4 border-b border-gold border-b-navy/15 bg-white px-4 py-3 sm:px-8 xl:px-12">
                <img
                    src="/LESTARI.png"
                    alt="Lestari Memorial Park"
                    width={1996}
                    height={675}
                    className="h-10 w-auto"
                />
            </header>
            <div className="mx-auto w-full max-w-[912px] px-4 pt-7 pb-12 sm:pt-10 sm:pb-20">
                <h1 className="text-center text-2xl font-semibold sm:text-3xl">
                    Pengajuan layanan pemakaman
                </h1>
                {!receipt && step === 0 && (
                    <p className="mx-auto mt-3 max-w-xl text-center text-base text-navy/80">
                        Turut berduka cita. Kami akan menemani Anda langkah demi
                        langkah.
                    </p>
                )}
                <div className="mt-5 rounded-xl border border-navy/15 bg-white p-4 sm:mt-7 sm:p-10 lg:p-12">
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
                                                        : 'bg-navy/30')
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
                                                      : 'border-navy/55 bg-white text-navy')
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
                                                'text-xs sm:text-sm ' +
                                                (index === step
                                                    ? 'font-semibold'
                                                    : 'text-navy/80')
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
                            <ReceiptCard receipt={receipt} />
                        ) : (
                            <form
                                noValidate
                                className="space-y-5"
                                onSubmit={submit}
                            >
                                <h2
                                    ref={heading}
                                    tabIndex={-1}
                                    className="border-b border-b-gold pb-3 text-xl font-semibold sm:text-2xl"
                                >
                                    <span className="block text-sm font-normal text-navy/75">
                                        Langkah {step + 1} dari 4
                                    </span>
                                    {labels[step]}
                                </h2>
                                {restored && (
                                    <p
                                        role="status"
                                        className="rounded-md border border-navy/25 p-3 text-sm"
                                    >
                                        Isian sebelumnya dipulihkan di perangkat
                                        ini.{' '}
                                        <button
                                            type="button"
                                            onClick={startOver}
                                            className={linkClass}
                                        >
                                            Mulai baru
                                        </button>
                                    </p>
                                )}
                                {messages.length > 0 && (
                                    <div
                                        ref={errors}
                                        tabIndex={-1}
                                        role="alert"
                                        className="rounded-md border border-danger bg-danger-soft p-3 text-navy"
                                    >
                                        <p className="flex items-center gap-2 font-semibold text-danger">
                                            <CircleAlert
                                                className="size-5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Ada yang perlu diperiksa.
                                        </p>
                                        <ul className="mt-1 space-y-1">
                                            {messages.map((message) => (
                                                <li key={message}>{message}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                {step === 0 && (
                                    <p className="text-sm text-navy/80 sm:text-base">
                                        Yang perlu disiapkan: nomor WhatsApp
                                        Anda, nama almarhum/almarhumah, dan
                                        perkiraan waktu pemakaman. KTP dan surat
                                        kematian boleh menyusul. Tanda * berarti
                                        wajib diisi.
                                    </p>
                                )}
                                {step === 1 || step === 2 ? (
                                    <p className="text-sm text-navy/75">
                                        Tanda * berarti wajib diisi.
                                    </p>
                                ) : null}
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
                                            'Nama lengkap Anda',
                                        )}
                                        {input(
                                            'applicant_whatsapp',
                                            'Nomor WhatsApp Anda',
                                            'tel',
                                        )}
                                        {choose(
                                            'relationship_to_deceased',
                                            'Hubungan dengan almarhum/almarhumah',
                                            'Pilih hubungan',
                                            relationships.map((value) => [
                                                value,
                                                value,
                                            ]),
                                            { className: 'sm:col-span-2' },
                                        )}
                                        {form.data.relationship_to_deceased ===
                                            'Lainnya' &&
                                            input(
                                                'relationship_other',
                                                'Sebutkan hubungan',
                                                'text',
                                                { className: 'sm:col-span-2' },
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
                                        {input(
                                            'funeral_home_location',
                                            'Lokasi rumah duka',
                                            'text',
                                            { className: 'sm:col-span-2' },
                                        )}
                                    </div>
                                )}
                                {step === 2 && (
                                    <div className="grid gap-4 sm:grid-cols-2 sm:gap-5">
                                        {choose(
                                            'has_plot',
                                            'Sudah memiliki lahan makam?',
                                            'Pilih jawaban',
                                            yesNo,
                                            { className: 'sm:col-span-2' },
                                        )}
                                        {form.data.has_plot === '1' && (
                                            <>
                                                {choose(
                                                    'burial_site',
                                                    'Lokasi makam',
                                                    'Belum diketahui',
                                                    [
                                                        [
                                                            'karawang',
                                                            'Karawang',
                                                        ],
                                                        [
                                                            'tangerang',
                                                            'Tangerang',
                                                        ],
                                                    ],
                                                    { optional: true },
                                                )}
                                                {choose(
                                                    'paid_in_full',
                                                    'Pembayaran sudah lunas?',
                                                    'Belum diketahui',
                                                    yesNo,
                                                    { optional: true },
                                                )}
                                                {input('zone', 'Zona', 'text', {
                                                    optional: true,
                                                    hint: 'Tertera di sertifikat kepemilikan makam.',
                                                })}
                                                {input(
                                                    'lot_number',
                                                    'Nomor lahan',
                                                    'text',
                                                    {
                                                        optional: true,
                                                        hint: 'Tertera di sertifikat kepemilikan makam.',
                                                    },
                                                )}
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
                                            className="space-y-3"
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
                                                {(
                                                    Object.entries(
                                                        documentLabels,
                                                    ) as [
                                                        keyof typeof documentLabels,
                                                        string,
                                                    ][]
                                                )
                                                    .filter(([type]) =>
                                                        visibleDocument(type),
                                                    )
                                                    .map(([field, label]) => {
                                                        const selected =
                                                            form.data.documents[
                                                                field
                                                            ];
                                                        return (
                                                            <li
                                                                key={field}
                                                                className="space-y-2 p-3 sm:px-4"
                                                            >
                                                                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                                                    <p
                                                                        id={
                                                                            field +
                                                                            '-label'
                                                                        }
                                                                        className="font-medium"
                                                                    >
                                                                        {label}
                                                                    </p>
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
                                                                </div>
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
                                                            </li>
                                                        );
                                                    })}
                                            </ul>
                                        </section>
                                        <section
                                            aria-labelledby="consent-heading"
                                            className="space-y-3 rounded-lg border border-navy/25 p-4"
                                        >
                                            <h3
                                                id="consent-heading"
                                                className="text-lg font-semibold"
                                            >
                                                Persetujuan penggunaan data
                                            </h3>
                                            <div>
                                                <label className="flex min-h-11 items-start gap-3 text-base leading-relaxed">
                                                    <input
                                                        id="field-consent_accepted"
                                                        className="mt-1 size-5 shrink-0"
                                                        type="checkbox"
                                                        required
                                                        aria-invalid={
                                                            form.errors
                                                                .consent_accepted
                                                                ? true
                                                                : undefined
                                                        }
                                                        aria-describedby={
                                                            form.errors
                                                                .consent_accepted
                                                                ? 'consent-error'
                                                                : undefined
                                                        }
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
                                                {form.errors
                                                    .consent_accepted && (
                                                    <ErrorText id="consent-error">
                                                        {
                                                            form.errors
                                                                .consent_accepted
                                                        }
                                                    </ErrorText>
                                                )}
                                            </div>
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
                                                <div
                                                    id="challenge-error"
                                                    tabIndex={-1}
                                                    role="alert"
                                                >
                                                    <ErrorText>
                                                        {challengeError}
                                                    </ErrorText>
                                                </div>
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
                <p className="mt-6 text-center text-base text-navy/80">
                    Butuh bantuan? Hubungi kami lewat WhatsApp{' '}
                    <a
                        className={linkClass}
                        href={helpWhatsApp.href}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {helpWhatsApp.label}
                        <span className="sr-only"> (membuka tab baru)</span>
                    </a>
                    .
                </p>
            </div>
        </main>
    );
}
