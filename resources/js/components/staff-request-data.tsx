import { router, useForm } from '@inertiajs/react';
import { ClipboardList, Pencil } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import PhoneInput from './phone-input';
import { applicantPhoneInput, validApplicantPhone } from '../lib/phone';
import {
    buttonClass,
    dateOnly,
    dateTime,
    fieldClass,
    linkClass,
} from '../lib/funeral';

export type RequestData = {
    applicant_name: string;
    applicant_whatsapp_e164: string;
    relationship_to_deceased: string;
    deceased_name: string;
    deceased_date_of_death: string;
    funeral_home_location: string;
    has_plot: boolean | number;
    burial_site: string | null;
    zone: string | null;
    lot_number: string | null;
    paid_in_full: boolean | number | null;
    current_local: string;
    applicant_estimated_burial_at: string;
    consented_at: string;
};

type Section = 'applicant' | 'deceased' | 'burial';

const sections = {
    applicant: 'Pemohon',
    deceased: 'Almarhum/almarhumah',
    burial: 'Makam dan pembayaran',
} satisfies Record<Section, string>;

const labels: Record<string, string> = {
    applicant_name: 'Nama pemohon',
    applicant_whatsapp: 'Nomor WhatsApp',
    relationship_to_deceased: 'Hubungan dengan almarhum/almarhumah',
    deceased_name: 'Nama lengkap',
    deceased_date_of_death: 'Tanggal meninggal',
    funeral_home_location: 'Lokasi rumah duka',
    has_plot: 'Kepemilikan lahan',
    burial_site: 'Lokasi makam',
    zone: 'Zona',
    lot_number: 'Nomor lahan',
    paid_in_full: 'Pelunasan',
    current_burial_at: 'Jadwal pemakaman',
    burial_date: 'Tanggal pemakaman',
    burial_time: 'Waktu pemakaman (WIB)',
};

const discardMessage =
    'Ada perubahan yang belum disimpan. Tinggalkan perubahan?';
const fieldGrid =
    'grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 sm:[&>:last-child:nth-child(odd)]:col-span-2';

function localDate(iso: string) {
    return iso.split('-').reverse().join('/');
}

function isoDate(input: string) {
    if (!/^\d{2}\/\d{2}\/\d{4}$/.test(input)) return null;
    const value = input.split('/').reverse().join('-');
    const date = new Date(value + 'T00:00:00Z');
    return Number.isNaN(date.getTime()) ||
        date.toISOString().slice(0, 10) !== value ||
        value.startsWith('0000')
        ? null
        : value;
}

function digits(input: string, date: boolean) {
    const value = input.replace(/\D/g, '').slice(0, date ? 8 : 4);
    return [value.slice(0, 2), value.slice(2, 4), value.slice(4)]
        .filter(Boolean)
        .join(date ? '/' : ':');
}

function values(section: Section, record: RequestData): Record<string, string> {
    if (section === 'applicant')
        return {
            applicant_name: record.applicant_name,
            applicant_whatsapp: applicantPhoneInput(
                record.applicant_whatsapp_e164,
            ),
            relationship_to_deceased: record.relationship_to_deceased,
        };
    if (section === 'deceased')
        return {
            deceased_name: record.deceased_name,
            deceased_date_of_death: localDate(record.deceased_date_of_death),
            funeral_home_location: record.funeral_home_location,
        };
    return {
        has_plot: record.has_plot ? '1' : '0',
        burial_site: record.burial_site ?? '',
        zone: record.zone ?? '',
        lot_number: record.lot_number ?? '',
        paid_in_full:
            record.paid_in_full === null ? '' : record.paid_in_full ? '1' : '0',
        burial_date: localDate(record.current_local.slice(0, 10)),
        burial_time: record.current_local.slice(11, 16),
    };
}

function SectionEditor({
    section,
    record,
    url,
    close,
    onDirty,
}: {
    section: Section;
    record: RequestData;
    url: string;
    close: () => void;
    onDirty: (dirty: boolean) => void;
}) {
    const form = useForm(values(section, record));

    useEffect(() => {
        onDirty(form.isDirty);
    }, [form.isDirty, onDirty]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = { ...form.data };
        if (section === 'deceased') {
            const date = isoDate(data.deceased_date_of_death);
            if (!date) {
                form.setError(
                    'deceased_date_of_death',
                    'Masukkan tanggal yang valid dalam format DD/MM/YYYY.',
                );
                return;
            }
            data.deceased_date_of_death = date;
        } else if (section === 'burial') {
            const date = isoDate(data.burial_date);
            if (!date) {
                form.setError(
                    'burial_date',
                    'Masukkan tanggal yang valid dalam format DD/MM/YYYY.',
                );
                return;
            }
            if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(data.burial_time)) {
                form.setError(
                    'burial_time',
                    'Masukkan waktu yang valid dalam format 24 jam, misalnya 09:00.',
                );
                return;
            }
            data.current_burial_at =
                date + 'T' + data.burial_time + ':00+07:00';
            delete data.burial_date;
            delete data.burial_time;
        }
        form.transform(() => data);
        form.patch(url, { preserveScroll: true, onSuccess: close });
    }

    function select(
        field: string,
        options: Record<string, string>,
        required = false,
    ) {
        return (
            <label className="block text-sm font-medium">
                {labels[field]}
                <select
                    className={fieldClass + ' mt-1'}
                    value={form.data[field]}
                    required={required}
                    onChange={(event) =>
                        form.setData(field, event.target.value)
                    }
                >
                    {Object.entries(options).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
        );
    }

    function input(field: string) {
        const date =
            field === 'burial_date' || field === 'deceased_date_of_death';
        const time = field === 'burial_time';
        return (
            <label className="block text-sm font-medium">
                {labels[field]}
                {field === 'applicant_whatsapp' ? (
                    <span className="mt-1 block">
                        <PhoneInput
                            required
                            validatePhone={validApplicantPhone}
                            value={form.data[field]}
                            onValueChange={(value) =>
                                form.setData(field, value)
                            }
                        />
                    </span>
                ) : (
                    <input
                        className={fieldClass + ' mt-1'}
                        required={!['lot_number', 'zone'].includes(field)}
                        name={field}
                        inputMode={date || time ? 'numeric' : undefined}
                        maxLength={
                            date
                                ? 10
                                : time
                                  ? 5
                                  : field === 'relationship_to_deceased'
                                    ? 100
                                    : 255
                        }
                        placeholder={
                            date
                                ? 'DD/MM/YYYY'
                                : time
                                  ? 'JJ:MM (24 jam)'
                                  : undefined
                        }
                        value={form.data[field]}
                        onChange={(event) => {
                            form.clearErrors(field);
                            form.setData(
                                field,
                                date || time
                                    ? digits(event.target.value, date)
                                    : event.target.value,
                            );
                        }}
                    />
                )}
            </label>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-3">
            <fieldset disabled={form.processing} className={fieldGrid}>
                {section !== 'burial' ? (
                    Object.keys(form.data).map((field) => (
                        <div key={field}>{input(field)}</div>
                    ))
                ) : (
                    <>
                        {select('has_plot', { '0': 'Tidak', '1': 'Ya' }, true)}
                        {form.data.has_plot === '1' && (
                            <>
                                {select('burial_site', {
                                    '': 'Belum ditentukan',
                                    karawang: 'Karawang',
                                    tangerang: 'Tangerang',
                                })}
                                {input('zone')}
                                {input('lot_number')}
                                {select('paid_in_full', {
                                    '': 'Belum diketahui',
                                    '0': 'Belum lunas',
                                    '1': 'Lunas',
                                })}
                            </>
                        )}
                        {input('burial_date')}
                        {input('burial_time')}
                    </>
                )}
            </fieldset>
            {Object.keys(form.errors).length > 0 && (
                <ul className="space-y-1 text-sm text-red-900" role="alert">
                    {Object.entries(form.errors).map(([field, message]) => (
                        <li key={field}>
                            {labels[field] ?? field}: {message}
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-2">
                <button className={buttonClass} disabled={form.processing}>
                    Simpan
                </button>
                <button
                    type="button"
                    className={linkClass + ' min-h-11 px-3'}
                    disabled={form.processing}
                    onClick={() => {
                        if (!form.isDirty || window.confirm(discardMessage))
                            close();
                    }}
                >
                    Batal
                </button>
            </div>
        </form>
    );
}

export function DetailValue({
    label,
    value,
    className = '',
}: {
    label: string;
    value: string;
    className?: string;
}) {
    return (
        <div className={'min-w-0 ' + className}>
            <dt className="text-sm text-navy/70">{label}</dt>
            <dd className="mt-0.5 font-medium break-words">{value}</dd>
        </div>
    );
}

export default function StaffRequestData({
    record,
    canEdit,
    url,
}: {
    record: RequestData;
    canEdit: boolean;
    url: string;
}) {
    const [editing, setEditing] = useState<Section | null>(null);
    const [dirty, setDirty] = useState(false);
    const current = dateTime(record.current_local + ':00+07:00');
    const estimate = dateTime(record.applicant_estimated_burial_at);

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        const unsubscribe = router.on('before', ({ detail: { visit } }) => {
            if (
                visit.url.pathname === url &&
                (visit.method === 'get' || visit.method === 'patch')
            )
                return;
            if (!window.confirm(discardMessage)) return false;
            setDirty(false);
            setEditing(null);
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            unsubscribe();
        };
    }, [dirty, url]);

    const display = {
        applicant: values('applicant', record),
        deceased: {
            ...values('deceased', record),
            deceased_date_of_death: dateOnly(record.deceased_date_of_death),
        },
        burial: {
            has_plot: record.has_plot ? 'Ya' : 'Tidak',
            ...(record.has_plot
                ? {
                      burial_site:
                          record.burial_site === 'karawang'
                              ? 'Karawang'
                              : record.burial_site === 'tangerang'
                                ? 'Tangerang'
                                : 'Belum ditentukan',
                      zone: record.zone ?? 'Belum diisi',
                      lot_number: record.lot_number ?? 'Belum diisi',
                      paid_in_full:
                          record.paid_in_full === null
                              ? 'Belum diketahui'
                              : record.paid_in_full
                                ? 'Lunas'
                                : 'Belum lunas',
                  }
                : {}),
        },
    };

    function close() {
        setEditing(null);
        setDirty(false);
    }

    return (
        <section
            id="data-pengajuan"
            className="order-2 min-w-0 rounded-lg border border-navy/15 bg-white p-4 sm:p-5"
        >
            <h2 className="flex items-center gap-2 text-lg font-semibold">
                <ClipboardList className="size-5" aria-hidden="true" /> Data
                pengajuan
            </h2>
            <div className="mt-3 divide-y divide-navy/15">
                {(Object.keys(sections) as Section[]).map((section) => (
                    <section key={section} className="space-y-3 py-4">
                        <header className="flex items-center justify-between gap-3">
                            <h3 className="font-semibold">
                                {sections[section]}
                            </h3>
                            {canEdit && editing !== section && (
                                <button
                                    type="button"
                                    className={
                                        linkClass +
                                        ' inline-flex min-h-9 items-center gap-1 text-sm'
                                    }
                                    aria-label={'Ubah ' + sections[section]}
                                    onClick={() => {
                                        if (
                                            !dirty ||
                                            window.confirm(discardMessage)
                                        ) {
                                            setDirty(false);
                                            setEditing(section);
                                        }
                                    }}
                                >
                                    <Pencil
                                        className="size-4"
                                        aria-hidden="true"
                                    />{' '}
                                    Ubah
                                </button>
                            )}
                        </header>
                        {editing === section && canEdit ? (
                            <SectionEditor
                                key={section}
                                section={section}
                                record={record}
                                url={url}
                                close={close}
                                onDirty={setDirty}
                            />
                        ) : (
                            <dl
                                className={
                                    section === 'burial' && !record.has_plot
                                        ? 'grid gap-3'
                                        : fieldGrid
                                }
                            >
                                {Object.entries(display[section]).map(
                                    ([field, value]) => (
                                        <DetailValue
                                            key={field}
                                            label={labels[field]}
                                            value={value}
                                        />
                                    ),
                                )}
                                {section === 'burial' && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-sm text-navy/70">
                                            Jadwal pemakaman
                                        </dt>
                                        <dd className="mt-0.5 font-medium">
                                            {current}
                                        </dd>
                                        {estimate !== current && (
                                            <dd className="mt-1 text-sm text-navy/70">
                                                Estimasi awal pemohon:{' '}
                                                {estimate}
                                            </dd>
                                        )}
                                    </div>
                                )}
                            </dl>
                        )}
                    </section>
                ))}
            </div>
            <dl className="border-t border-navy/15 pt-3">
                <DetailValue
                    label="Persetujuan dicatat"
                    value={dateTime(record.consented_at)}
                />
            </dl>
        </section>
    );
}
