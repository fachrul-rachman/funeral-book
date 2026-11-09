import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Clock3,
    Inbox,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import StaffLayout from '../components/staff-layout';
import {
    buttonClass,
    dateTime,
    duration,
    fieldClass,
    linkClass,
    statusColors,
    statuses,
    visitationCorrectionMessage,
} from '../lib/funeral';
import type { Pagination } from '../lib/funeral';

type Schedule = {
    id: string;
    reference_code: string;
    deceased_name: string;
    current_burial_at: string;
    burial_site: string | null;
    schedule_day: string;
};
type Analytics = { trend: number[]; distribution: Record<string, number> };
type Props = {
    period: string;
    today: string;
    schedule: Schedule[];
    metrics: {
        today: number;
        new_requests: number;
        period_count: number;
        response_seconds: number | null;
    };
    analytics: Analytics | null;
    deliveryAlerts:
        | (Pagination<{
              id: string;
              funeral_request_id: string;
              reference_code: string;
              state: string;
              recipient_role: string | null;
              last_error_code: string | null;
              source: 'whatsapp' | 'visitation';
              validation_fields: string[];
          }> & { total: number })
        | null;
};

function ScheduleList({ label, rows }: { label: string; rows: Schedule[] }) {
    return (
        <section className="min-w-0 rounded-lg border border-navy/20">
            <h3 className="border-b border-gold/70 px-4 py-2 font-semibold">
                {label}
            </h3>
            {rows.length === 0 ? (
                <p className="px-4 py-2 text-sm text-navy/75">
                    Belum ada jadwal.
                </p>
            ) : (
                <ul className="divide-y divide-navy/15">
                    {rows.map((row) => (
                        <li key={row.id} className="space-y-1 px-4 py-3">
                            <Link
                                className={
                                    linkClass +
                                    ' inline-flex items-center gap-1 break-words'
                                }
                                href={'/staf/pengajuan/' + row.id}
                            >
                                {row.deceased_name}{' '}
                                <ArrowUpRight
                                    className="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                            </Link>
                            <p className="text-sm">
                                {dateTime(row.current_burial_at)}
                            </p>
                            <p className="text-sm text-navy/75">
                                {row.burial_site ?? 'Lokasi belum ditentukan'}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function DailyBars({ counts, period }: { counts: number[]; period: string }) {
    const [hovered, setHovered] = useState<number | null>(null);
    const [focused, setFocused] = useState<number | null>(null);
    const active = hovered ?? focused;
    const peak = Math.max(1, ...counts);
    const interval = Math.ceil(peak / 4);
    const ticks = Array.from(
        { length: Math.ceil(peak / interval) + 1 },
        (_, i) => i * interval,
    );
    const max = ticks[ticks.length - 1];
    const grid = {
        gridTemplateColumns: 'repeat(' + counts.length + ', minmax(0, 1fr))',
    };
    const formatDay = (day: number) =>
        new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(
            new Date(
                period + '-' + String(day + 1).padStart(2, '0') + 'T00:00:00Z',
            ),
        );

    return (
        <div className="min-w-0 rounded-lg border border-navy/20 p-4">
            <h3 className="font-semibold">Tren pengajuan harian</h3>
            <p className="mt-2 text-xs text-navy/75">Jumlah</p>
            <div className="mt-1 grid grid-cols-[2.75rem_minmax(0,1fr)]">
                <div
                    className="relative h-40 text-right text-xs text-navy/75 tabular-nums sm:h-44"
                    aria-hidden="true"
                >
                    {ticks.map((value) => (
                        <span
                            key={value}
                            className="absolute right-2 translate-y-1/2"
                            style={{ bottom: (value / max) * 100 + '%' }}
                        >
                            {value.toLocaleString('id-ID')}
                        </span>
                    ))}
                </div>
                <div
                    className="relative h-40 border-l border-navy/20 sm:h-44"
                    onMouseLeave={() => setHovered(null)}
                >
                    {ticks.map((value) => (
                        <div
                            key={value}
                            aria-hidden="true"
                            className="absolute inset-x-0 border-t border-navy/15"
                            style={{ bottom: (value / max) * 100 + '%' }}
                        />
                    ))}
                    <div className="relative grid h-full" style={grid}>
                        {counts.map((count, day) => (
                            <button
                                key={day}
                                type="button"
                                aria-label={
                                    formatDay(day) + ': ' + count + ' pengajuan'
                                }
                                className="flex h-full min-w-0 items-end justify-center rounded-sm hover:bg-navy/5"
                                onMouseEnter={() => setHovered(day)}
                                onFocus={() => setFocused(day)}
                                onBlur={() => setFocused(null)}
                                onClick={() => setFocused(day)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                        setHovered(null);
                                        setFocused(null);
                                    }
                                }}
                            >
                                <span
                                    aria-hidden="true"
                                    className={
                                        'w-[68%] rounded-t-sm ' +
                                        (active === day ? 'bg-gold' : 'bg-navy')
                                    }
                                    style={{
                                        height: (count / max) * 100 + '%',
                                    }}
                                />
                            </button>
                        ))}
                    </div>
                    {active !== null && (
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-md bg-navy px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-sm"
                            style={{
                                left:
                                    'clamp(4.25rem, ' +
                                    ((active + 0.5) / counts.length) * 100 +
                                    '%, calc(100% - 4.25rem))',
                            }}
                        >
                            {formatDay(active)}:{' '}
                            <strong>{counts[active]} pengajuan</strong>
                        </div>
                    )}
                </div>
                <div aria-hidden="true" />
                <div
                    className="mt-2 grid text-center text-[10px] text-navy/75 tabular-nums"
                    style={grid}
                    aria-hidden="true"
                >
                    {counts.map((_, day) => (
                        <span
                            key={day}
                            className={
                                day === 0 ||
                                day === counts.length - 1 ||
                                (day + 1) % 5 === 0
                                    ? ''
                                    : 'invisible xl:visible'
                            }
                        >
                            {day + 1}
                        </span>
                    ))}
                </div>
            </div>
            <p className="mt-2 text-center text-xs text-navy/75">Tanggal</p>
        </div>
    );
}

function StatusDistribution({
    distribution,
}: {
    distribution: Analytics['distribution'];
}) {
    const total = Object.values(distribution).reduce(
        (sum, count) => sum + count,
        0,
    );
    const format = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });
    return (
        <section className="min-w-0 space-y-3 rounded-lg border border-navy/20 p-4">
            <h3 className="font-semibold">Distribusi status pengajuan</h3>
            <ul className="space-y-2.5">
                {Object.entries(distribution).map(([status, count]) => {
                    const percent = total ? (count / total) * 100 : 0;
                    return (
                        <li key={status} className="space-y-1">
                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                <span className="flex min-w-0 items-center gap-2">
                                    <span
                                        className={
                                            'size-2 shrink-0 rounded-full ' +
                                            statusColors[status]
                                        }
                                        aria-hidden="true"
                                    />
                                    {statuses[status]}
                                </span>
                                <span className="flex shrink-0 items-baseline gap-2 tabular-nums">
                                    <strong className="text-xl font-semibold">
                                        {format.format(count)}
                                    </strong>
                                    <span className="min-w-12 text-right text-navy/75">
                                        ({format.format(percent)}%)
                                    </span>
                                </span>
                            </div>
                            <div
                                className="h-1 overflow-hidden rounded-full bg-[#EBEDF0]"
                                aria-hidden="true"
                            >
                                <div
                                    className={
                                        'h-full rounded-full ' +
                                        statusColors[status]
                                    }
                                    style={{ width: percent + '%' }}
                                />
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

export default function Staff({
    period,
    today,
    schedule,
    metrics,
    analytics,
    deliveryAlerts,
}: Props) {
    const form = useForm({ period });
    function filter(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.get('/staf', { preserveScroll: true });
    }
    const periodTitle = new Intl.DateTimeFormat('id-ID', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(period + '-01T00:00:00Z'));
    const kpis = [
        {
            label: 'Perlu ditindaklanjuti',
            value: metrics.new_requests,
            icon: ClipboardList,
            href: '/staf/pengajuan?status=new_request',
        },
        {
            label: 'Pengajuan masuk hari ini',
            value: metrics.today,
            icon: Inbox,
        },
    ];
    return (
        <StaffLayout>
            <main className="space-y-5 p-4 sm:p-6 xl:px-8">
                <Head title="Dasbor staf" />
                <header className="border-b border-gold pb-4">
                    <h1 className="text-3xl font-semibold">Dasbor staf</h1>
                    <p className="mt-1 text-navy/75">
                        Ringkasan operasional dan jadwal pemakaman.
                    </p>
                </header>
                <div className="grid gap-4 sm:grid-cols-2">
                    {kpis.map(({ label, value, icon: Icon, href }) => {
                        const content = (
                            <>
                                <p className="flex items-center gap-2 font-medium">
                                    <Icon
                                        className="size-5"
                                        aria-hidden="true"
                                    />{' '}
                                    {label}
                                    {href && (
                                        <ArrowUpRight
                                            className="ml-auto size-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                </p>
                                <p className="mt-1 text-4xl leading-tight font-semibold tabular-nums">
                                    {value.toLocaleString('id-ID')}
                                </p>
                            </>
                        );
                        const card =
                            'min-w-0 rounded-lg border border-t-3 border-navy/20 border-t-gold px-4 py-3';
                        return href ? (
                            <Link
                                key={label}
                                href={href}
                                className={card + ' hover:bg-navy/5'}
                            >
                                {content}
                            </Link>
                        ) : (
                            <div key={label} className={card}>
                                {content}
                            </div>
                        );
                    })}
                </div>
                {deliveryAlerts && deliveryAlerts.total > 0 && (
                    <section
                        id="whatsapp"
                        className="space-y-3 rounded-lg border border-gold bg-gold/10 p-4"
                    >
                        <h2 className="flex items-center gap-2 font-semibold">
                            <TriangleAlert
                                className="size-5"
                                aria-hidden="true"
                            />{' '}
                            Pengiriman perlu diperiksa ({deliveryAlerts.total})
                        </h2>
                        <ul className="space-y-1">
                            {deliveryAlerts.data.map((alert) => (
                                <li
                                    key={alert.source + '-' + alert.id}
                                    className="text-sm break-words"
                                >
                                    <Link
                                        className={linkClass}
                                        href={
                                            '/staf/pengajuan/' +
                                            alert.funeral_request_id
                                        }
                                    >
                                        {alert.reference_code}
                                    </Link>
                                    {', '}
                                    {alert.source === 'visitation'
                                        ? 'Tautan ziarah'
                                        : (alert.recipient_role ?? 'Pemohon')}
                                    {', '}
                                    {alert.state === 'failed'
                                        ? 'Gagal'
                                        : 'Hasil belum pasti'}
                                    {alert.last_error_code &&
                                        alert.validation_fields.length === 0 &&
                                        ' (Kode ' + alert.last_error_code + ')'}
                                    {alert.validation_fields.length > 0 && (
                                        <p className="mt-1 text-sm text-navy/80">
                                            {visitationCorrectionMessage(
                                                alert.validation_fields,
                                            )}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {(deliveryAlerts.prev_page_url ||
                            deliveryAlerts.next_page_url) && (
                            <nav
                                aria-label="Halaman notifikasi WhatsApp"
                                className="flex gap-3 text-sm"
                            >
                                {deliveryAlerts.prev_page_url && (
                                    <Link
                                        className={linkClass}
                                        href={deliveryAlerts.prev_page_url}
                                    >
                                        Sebelumnya
                                    </Link>
                                )}
                                {deliveryAlerts.next_page_url && (
                                    <Link
                                        className={linkClass}
                                        href={deliveryAlerts.next_page_url}
                                    >
                                        Berikutnya
                                    </Link>
                                )}
                            </nav>
                        )}
                    </section>
                )}
                <section className="space-y-3">
                    <h2 className="flex items-center gap-2 text-xl font-semibold">
                        <CalendarDays className="size-5" aria-hidden="true" />{' '}
                        Jadwal pemakaman
                    </h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        <ScheduleList
                            label="Hari ini"
                            rows={schedule.filter(
                                (row) => row.schedule_day === today,
                            )}
                        />
                        <ScheduleList
                            label="Besok"
                            rows={schedule.filter(
                                (row) => row.schedule_day !== today,
                            )}
                        />
                    </div>
                </section>
                <section
                    className="space-y-3"
                    aria-labelledby="analytics-heading"
                >
                    <header className="flex flex-wrap items-end justify-between gap-3 border-b border-gold pb-3">
                        <h2
                            id="analytics-heading"
                            className="flex items-center gap-2 text-xl font-semibold"
                        >
                            <Activity className="size-5" aria-hidden="true" />{' '}
                            Analitik {periodTitle}
                        </h2>
                        <form
                            onSubmit={filter}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <label className="text-sm font-medium">
                                Periode
                                <input
                                    className={fieldClass + ' mt-1'}
                                    required
                                    type="month"
                                    value={form.data.period}
                                    onChange={(event) =>
                                        form.setData(
                                            'period',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <button
                                className={buttonClass}
                                disabled={form.processing}
                            >
                                Terapkan
                            </button>
                            {form.errors.period && (
                                <p role="alert" className="w-full text-sm">
                                    {form.errors.period}
                                </p>
                            )}
                        </form>
                    </header>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-navy/20 px-4 py-3">
                            <dt className="flex items-center gap-2 text-sm font-medium">
                                <CalendarRange
                                    className="size-4"
                                    aria-hidden="true"
                                />{' '}
                                Pengajuan periode ini
                            </dt>
                            <dd className="mt-1 text-4xl leading-tight font-semibold tabular-nums">
                                {metrics.period_count.toLocaleString('id-ID')}
                            </dd>
                        </div>
                        <div className="rounded-lg border border-navy/20 px-4 py-3">
                            <dt className="flex items-center gap-2 text-sm font-medium">
                                <Clock3 className="size-4" aria-hidden="true" />{' '}
                                Rata-rata respons pertama
                            </dt>
                            <dd
                                className={
                                    metrics.response_seconds === null
                                        ? 'mt-2 font-medium text-navy/75'
                                        : 'mt-1 text-4xl leading-tight font-semibold tabular-nums'
                                }
                            >
                                {duration(metrics.response_seconds)}
                            </dd>
                        </div>
                    </dl>
                    {analytics && (
                        <div className="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                            <DailyBars
                                key={period}
                                counts={analytics.trend}
                                period={period}
                            />
                            <StatusDistribution
                                distribution={analytics.distribution}
                            />
                        </div>
                    )}
                </section>
            </main>
        </StaffLayout>
    );
}
