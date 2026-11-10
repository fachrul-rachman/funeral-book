import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useRef, useState } from 'react';
import Notice from '../components/notice';
import StaffLayout from '../components/staff-layout';
import {
    duration,
    fieldClass,
    linkClass,
    longDate,
    roleLabels,
    siteLabel,
    statuses,
    statusStyles,
    timeOnly,
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

function ScheduleList({
    label,
    day,
    rows,
}: {
    label: string;
    day: string;
    rows: Schedule[];
}) {
    return (
        <section className="min-w-0 rounded-lg border border-navy/20">
            <h3 className="border-b border-navy/15 px-4 py-2 font-semibold">
                {label}{' '}
                <span className="block text-sm font-normal text-navy/75 sm:inline">
                    {longDate(day)}
                </span>
            </h3>
            {rows.length === 0 ? (
                <p className="px-4 py-3 text-sm text-navy/75">
                    Belum ada jadwal.
                </p>
            ) : (
                <ul className="divide-y divide-navy/15">
                    {rows.map((row) => (
                        <li
                            key={row.id}
                            className="grid grid-cols-[3.75rem_minmax(0,1fr)] gap-3 px-4 py-3"
                        >
                            <p className="font-semibold tabular-nums">
                                {timeOnly(row.current_burial_at)}
                                <span className="block text-xs font-normal text-navy/75">
                                    WIB
                                </span>
                            </p>
                            <div className="min-w-0">
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
                                <p className="text-sm text-navy/75">
                                    {siteLabel(row.burial_site)}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function DailyBars({
    counts,
    period,
    today,
}: {
    counts: number[];
    period: string;
    today: string;
}) {
    const [hovered, setHovered] = useState<number | null>(null);
    const [focused, setFocused] = useState<number | null>(null);
    const todayMonth = today.slice(0, 7);
    const todayIndex = Number(today.slice(8, 10)) - 1;
    const [cursor, setCursor] = useState(
        period === todayMonth ? Math.min(todayIndex, counts.length - 1) : 0,
    );
    const buttons = useRef<(HTMLButtonElement | null)[]>([]);
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
    // Past days are plain, today is tinted gold, days not yet reached are grey.
    const when = (day: number): 'past' | 'today' | 'future' => {
        if (period < todayMonth) return 'past';
        if (period > todayMonth) return 'future';
        return day < todayIndex
            ? 'past'
            : day === todayIndex
              ? 'today'
              : 'future';
    };
    const tint = { past: '', today: 'bg-gold/25', future: 'bg-navy/[0.04]' };
    const whenLabel = { past: '', today: ', hari ini', future: ', belum tiba' };

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
                    <div
                        role="group"
                        aria-label="Pengajuan per hari, gunakan tombol panah"
                        className="relative grid h-full"
                        style={grid}
                    >
                        {counts.map((count, day) => (
                            <button
                                key={day}
                                ref={(element) => {
                                    buttons.current[day] = element;
                                }}
                                type="button"
                                tabIndex={day === cursor ? 0 : -1}
                                aria-label={
                                    formatDay(day) +
                                    whenLabel[when(day)] +
                                    ': ' +
                                    count +
                                    ' pengajuan'
                                }
                                className={
                                    'flex h-full min-w-0 items-end justify-center rounded-sm hover:bg-navy/10 ' +
                                    tint[when(day)]
                                }
                                onMouseEnter={() => setHovered(day)}
                                onFocus={() => {
                                    setFocused(day);
                                    setCursor(day);
                                }}
                                onBlur={() => setFocused(null)}
                                onClick={() => setFocused(day)}
                                onKeyDown={(event) => {
                                    const moves: Record<string, number> = {
                                        ArrowRight: day + 1,
                                        ArrowLeft: day - 1,
                                        Home: 0,
                                        End: counts.length - 1,
                                    };
                                    if (event.key in moves) {
                                        event.preventDefault();
                                        const next = Math.min(
                                            Math.max(moves[event.key], 0),
                                            counts.length - 1,
                                        );
                                        buttons.current[next]?.focus();
                                    }
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
                                        (active === day
                                            ? 'bg-[#526B9F]'
                                            : 'bg-navy')
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
                            {formatDay(active)}
                            {when(active) === 'today' && ' (hari ini)'}:{' '}
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
                                when(day) === 'today'
                                    ? 'rounded-sm bg-gold/60 font-semibold text-navy'
                                    : day === 0 ||
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
            {period === todayMonth && (
                <p className="mt-2 flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-navy/75">
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            className="size-3 rounded-sm border border-gold bg-gold/25"
                            aria-hidden="true"
                        />
                        Hari ini, {longDate(today)}
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            className="size-3 rounded-sm border border-navy/20 bg-navy/[0.04]"
                            aria-hidden="true"
                        />
                        Belum tiba
                    </span>
                </p>
            )}
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
                                            statusStyles[status].dot
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
                                        statusStyles[status].dot
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
    function changePeriod(value: string) {
        form.setData('period', value);
        if (!value) return;
        form.transform(() => ({ period: value }));
        form.get('/staf', { preserveScroll: true });
    }
    const periodTitle = new Intl.DateTimeFormat('id-ID', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(period + '-01T00:00:00Z'));
    const tomorrow = new Date(Date.parse(today + 'T00:00:00Z') + 86_400_000)
        .toISOString()
        .slice(0, 10);
    const waiting = metrics.new_requests;
    const failed = deliveryAlerts?.data.some(
        (alert) => alert.state === 'failed',
    );
    return (
        <StaffLayout>
            <main className="space-y-5 p-4 sm:p-6 xl:px-8">
                <Head title="Dasbor staf" />
                <header>
                    <h1 className="text-3xl font-semibold">Dasbor staf</h1>
                    <p className="mt-1 text-navy/75">
                        Ringkasan operasional dan jadwal pemakaman.
                    </p>
                </header>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Link
                        href="/staf/pengajuan?status=new_request"
                        className={
                            'min-w-0 rounded-lg border px-4 py-3 ' +
                            (waiting > 0
                                ? 'border-warning/60 bg-warning-soft hover:bg-warning-soft/70'
                                : 'border-navy/20 hover:bg-navy/5')
                        }
                    >
                        <p className="flex items-center gap-2 font-medium">
                            Perlu ditindaklanjuti
                            <ArrowUpRight
                                className="ml-auto size-4"
                                aria-hidden="true"
                            />
                        </p>
                        <p
                            className={
                                'mt-1 text-4xl leading-tight font-semibold tabular-nums ' +
                                (waiting > 0 ? 'text-warning' : '')
                            }
                        >
                            {waiting.toLocaleString('id-ID')}
                        </p>
                    </Link>
                    <div className="min-w-0 rounded-lg border border-navy/20 px-4 py-3">
                        <p className="font-medium">Pengajuan masuk hari ini</p>
                        <p className="mt-1 text-4xl leading-tight font-semibold tabular-nums">
                            {metrics.today.toLocaleString('id-ID')}
                        </p>
                    </div>
                </div>
                {deliveryAlerts && deliveryAlerts.total > 0 && (
                    <section id="whatsapp">
                        <Notice
                            tone={failed ? 'danger' : 'warning'}
                            title={
                                'Pengiriman perlu diperiksa (' +
                                deliveryAlerts.total +
                                ')'
                            }
                            className="space-y-2"
                        >
                            <ul className="space-y-1">
                                {deliveryAlerts.data.map((alert) => (
                                    <li
                                        key={alert.source + '-' + alert.id}
                                        className="break-words"
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
                                            : alert.recipient_role
                                              ? (roleLabels[
                                                    alert.recipient_role
                                                ] ?? alert.recipient_role)
                                              : 'Pemohon'}
                                        {', '}
                                        <strong>
                                            {alert.state === 'failed'
                                                ? 'Gagal'
                                                : 'Hasil belum pasti'}
                                        </strong>
                                        {alert.last_error_code &&
                                            alert.validation_fields.length ===
                                                0 &&
                                            ' (Kode ' +
                                                alert.last_error_code +
                                                ')'}
                                        {alert.validation_fields.length > 0 && (
                                            <span className="mt-1 block">
                                                {visitationCorrectionMessage(
                                                    alert.validation_fields,
                                                )}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {(deliveryAlerts.prev_page_url ||
                                deliveryAlerts.next_page_url) && (
                                <nav
                                    aria-label="Halaman notifikasi WhatsApp"
                                    className="flex gap-3"
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
                        </Notice>
                    </section>
                )}
                <section className="space-y-3">
                    <h2 className="text-xl font-semibold">Jadwal pemakaman</h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        <ScheduleList
                            label="Hari ini"
                            day={today}
                            rows={schedule.filter(
                                (row) => row.schedule_day === today,
                            )}
                        />
                        <ScheduleList
                            label="Besok"
                            day={tomorrow}
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
                    <header className="flex flex-wrap items-end justify-between gap-3 border-b border-navy/15 pb-3">
                        <h2
                            id="analytics-heading"
                            className="text-xl font-semibold"
                        >
                            Analitik {periodTitle}
                        </h2>
                        <div>
                            <label className="text-sm font-medium">
                                Periode
                                <input
                                    className={fieldClass + ' mt-1'}
                                    required
                                    type="month"
                                    value={form.data.period}
                                    onChange={(event) =>
                                        changePeriod(event.target.value)
                                    }
                                />
                            </label>
                            {form.errors.period && (
                                <p
                                    role="alert"
                                    className="mt-1 text-sm text-danger"
                                >
                                    {form.errors.period}
                                </p>
                            )}
                        </div>
                    </header>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-navy/20 px-4 py-3">
                            <dt className="text-sm font-medium">
                                Pengajuan periode ini
                            </dt>
                            <dd className="mt-1 text-4xl leading-tight font-semibold tabular-nums">
                                {metrics.period_count.toLocaleString('id-ID')}
                            </dd>
                        </div>
                        <div className="rounded-lg border border-navy/20 px-4 py-3">
                            <dt className="text-sm font-medium">
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
                                today={today}
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
