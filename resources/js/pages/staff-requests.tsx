import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowUpRight, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import StaffLayout from '../components/staff-layout';
import {
    buttonClass,
    dateTime,
    fieldClass,
    linkClass,
    statuses,
} from '../lib/funeral';
import type { Pagination } from '../lib/funeral';

type Row = {
    id: string;
    reference_code: string;
    status: string;
    deceased_name: string;
    submitted_at: string;
    burial_site: string | null;
    handler_name: string | null;
};
type Filters = { q: string; status: string; site: string; handler: string };
type Props = {
    filters: Partial<Filters>;
    requests: Pagination<Row>;
    handlers: { id: string; name: string }[];
};

export default function StaffRequests({ filters, requests, handlers }: Props) {
    const form = useForm({
        q: filters.q ?? '',
        status: filters.status ?? '',
        site: filters.site ?? '',
        handler: filters.handler ?? '',
    });
    function filter(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.get('/staf/pengajuan', {
            preserveState: true,
            preserveScroll: true,
        });
    }
    return (
        <StaffLayout>
            <main className="space-y-5 p-4 sm:p-7 xl:p-9">
                <Head title="Pengajuan" />
                <header className="border-b border-gold pb-4">
                    <h1 className="text-3xl font-semibold">Pengajuan</h1>
                    <p className="mt-1 text-navy/75">
                        Cari pengajuan dan buka detail untuk menindaklanjuti.
                    </p>
                </header>
                <form
                    onSubmit={filter}
                    className="grid gap-3 rounded-lg border border-navy/20 p-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <label className="text-sm font-medium">
                        Cari referensi atau nama
                        <input
                            className={fieldClass + ' mt-1'}
                            type="search"
                            maxLength={100}
                            value={form.data.q}
                            onChange={(e) => form.setData('q', e.target.value)}
                        />
                    </label>
                    <label className="text-sm font-medium">
                        Status
                        <select
                            className={fieldClass + ' mt-1'}
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            <option value="">Semua status</option>
                            <option value="active">Antrean aktif</option>
                            {Object.entries(statuses).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-medium">
                        Lokasi makam
                        <select
                            className={fieldClass + ' mt-1'}
                            value={form.data.site}
                            onChange={(e) =>
                                form.setData('site', e.target.value)
                            }
                        >
                            <option value="">Semua lokasi</option>
                            <option value="karawang">Karawang</option>
                            <option value="tangerang">Tangerang</option>
                        </select>
                    </label>
                    <label className="text-sm font-medium">
                        Penanggung jawab
                        <select
                            className={fieldClass + ' mt-1'}
                            value={form.data.handler}
                            onChange={(e) =>
                                form.setData('handler', e.target.value)
                            }
                        >
                            <option value="">Semua staf</option>
                            {handlers.map((handler) => (
                                <option key={handler.id} value={handler.id}>
                                    {handler.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-center gap-3 sm:col-span-full">
                        <button
                            className={buttonClass}
                            disabled={form.processing}
                        >
                            <Search className="size-4" aria-hidden="true" />{' '}
                            Terapkan
                        </button>
                        <Link
                            className={linkClass}
                            href="/staf/pengajuan"
                            preserveState={false}
                        >
                            Reset filter
                        </Link>
                    </div>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="sm:col-span-full">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                </form>
                {!requests.data.length ? (
                    <p className="rounded-lg border border-navy/20 p-5">
                        Tidak ada pengajuan yang cocok.
                    </p>
                ) : (
                    <ul className="divide-y divide-navy/15 rounded-lg border border-navy/20">
                        {requests.data.map((row) => (
                            <li
                                key={row.id}
                                className="grid gap-2 px-4 py-3 text-sm md:grid-cols-3"
                            >
                                <div className="min-w-0">
                                    <Link
                                        className={
                                            linkClass +
                                            ' inline-flex items-center gap-1'
                                        }
                                        href={'/staf/pengajuan/' + row.id}
                                    >
                                        {row.reference_code}
                                        <ArrowUpRight
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                    <p className="break-words">
                                        {row.deceased_name}
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {statuses[row.status]}
                                    </p>
                                    <p className="break-words">
                                        {row.handler_name ??
                                            'Belum ada penanggung jawab'}
                                    </p>
                                </div>
                                <div>
                                    <p>{dateTime(row.submitted_at)}</p>
                                    <p className="text-navy/75">
                                        {row.burial_site ??
                                            'Lokasi belum ditentukan'}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <nav
                    className="flex flex-wrap items-center gap-3 text-sm"
                    aria-label="Halaman pengajuan"
                >
                    {requests.prev_page_url && (
                        <Link
                            className={linkClass}
                            href={requests.prev_page_url}
                        >
                            Sebelumnya
                        </Link>
                    )}
                    <span>
                        Halaman {requests.current_page} dari{' '}
                        {requests.last_page}
                    </span>
                    {requests.next_page_url && (
                        <Link
                            className={linkClass}
                            href={requests.next_page_url}
                        >
                            Berikutnya
                        </Link>
                    )}
                </nav>
            </main>
        </StaffLayout>
    );
}
