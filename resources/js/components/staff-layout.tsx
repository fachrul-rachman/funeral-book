import { Link, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    LayoutDashboard,
    LogOut,
    Menu,
    ChevronLeft,
    ChevronRight,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { Auth } from '../types/auth';

export default function StaffLayout({ children }: { children: ReactNode }) {
    const {
        url,
        props: { auth, errors },
    } = usePage<{ auth: Auth; errors: Record<string, string> }>();
    const [compact, setCompact] = useState(
        () => sessionStorage.getItem('staff-sidebar') === 'compact',
    );
    const [mobileOpen, setMobileOpen] = useState(false);
    const pathname = url.split(/[?#]/)[0];
    const navigation = [
        { href: '/staf', label: 'Dasbor', icon: LayoutDashboard },
        { href: '/staf/pengajuan', label: 'Pengajuan', icon: ClipboardList },
        ...(auth.user?.role === 'superadmin'
            ? [{ href: '/staf/pengguna', label: 'Pengguna', icon: Users }]
            : []),
    ];

    function toggleCompact() {
        sessionStorage.setItem(
            'staff-sidebar',
            compact ? 'expanded' : 'compact',
        );
        setCompact(!compact);
    }

    return (
        <div
            className={
                compact
                    ? 'min-h-dvh lg:grid lg:grid-cols-[5rem_minmax(0,1fr)]'
                    : 'min-h-dvh lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]'
            }
        >
            <aside className="border-b-4 border-gold bg-navy text-white lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col lg:self-start lg:border-r-4 lg:border-b-0">
                <div className="flex min-h-18 items-center justify-between gap-2 px-4">
                    <Link
                        href="/staf"
                        aria-label="Dasbor Lestari Memorial Park"
                        className={compact ? 'lg:hidden' : ''}
                    >
                        <span className="block text-sm font-semibold tracking-wide">
                            Lestari Memorial Park
                        </span>
                        <span className="text-xs text-white/80">Area staf</span>
                    </Link>
                    <button
                        type="button"
                        className="rounded-md p-2 hover:bg-white/10 lg:hidden"
                        aria-controls="staff-nav"
                        aria-expanded={mobileOpen}
                        aria-label={
                            mobileOpen ? 'Tutup navigasi' : 'Buka navigasi'
                        }
                        onClick={() => setMobileOpen(!mobileOpen)}
                    >
                        {mobileOpen ? (
                            <X aria-hidden="true" />
                        ) : (
                            <Menu aria-hidden="true" />
                        )}
                    </button>
                    <button
                        type="button"
                        className="hidden size-10 shrink-0 items-center justify-center rounded-md text-white/80 hover:bg-white/10 hover:text-white lg:flex"
                        aria-controls="staff-nav"
                        aria-expanded={!compact}
                        aria-label={
                            compact ? 'Perluas sidebar' : 'Minimalkan sidebar'
                        }
                        title={
                            compact ? 'Perluas sidebar' : 'Minimalkan sidebar'
                        }
                        onClick={toggleCompact}
                    >
                        {compact ? (
                            <ChevronRight
                                className="size-5"
                                aria-hidden="true"
                            />
                        ) : (
                            <ChevronLeft
                                className="size-5"
                                aria-hidden="true"
                            />
                        )}
                    </button>
                </div>
                <div
                    id="staff-nav"
                    className={
                        (mobileOpen ? 'flex' : 'hidden') +
                        ' min-h-0 flex-col px-3 pb-3 lg:flex lg:flex-1'
                    }
                >
                    <nav
                        aria-label="Navigasi staf"
                        className="min-h-0 overflow-y-auto"
                    >
                        {navigation.map(({ href, label, icon: Icon }) => {
                            const active =
                                pathname === href ||
                                (href !== '/staf' &&
                                    pathname.startsWith(href + '/'));
                            return (
                                <Link
                                    key={href}
                                    href={href}
                                    aria-current={active ? 'page' : undefined}
                                    title={compact ? label : undefined}
                                    className={
                                        'mb-1 flex min-h-11 items-center gap-3 rounded-md border-l-2 px-3 text-sm font-medium ' +
                                        (active
                                            ? 'border-gold bg-white/15'
                                            : 'border-transparent hover:bg-white/10')
                                    }
                                    onClick={() => setMobileOpen(false)}
                                >
                                    <Icon
                                        className="size-5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span
                                        className={compact ? 'lg:sr-only' : ''}
                                    >
                                        {label}
                                    </span>
                                </Link>
                            );
                        })}
                    </nav>
                    <div className="mt-3 border-t border-white/20 pt-3 lg:mt-auto">
                        <Link
                            href="/keluar"
                            method="post"
                            as="button"
                            title={compact ? 'Keluar' : undefined}
                            className="flex min-h-11 w-full items-center gap-3 rounded-md border-l-2 border-transparent px-3 text-left text-sm hover:bg-white/10"
                        >
                            <LogOut
                                className="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className={compact ? 'lg:sr-only' : ''}>
                                Keluar
                            </span>
                        </Link>
                    </div>
                </div>
            </aside>
            <div className="min-w-0">
                <header className="hidden min-h-14 items-center justify-between gap-3 border-b border-gold/60 px-6 text-sm lg:flex">
                    <span className="font-medium">Operasional pemakaman</span>
                    <span className="min-w-0 truncate">{auth.user?.name}</span>
                </header>
                {errors.session && (
                    <p
                        role="alert"
                        className="mx-4 mt-4 rounded-lg border border-gold bg-gold/10 p-3 text-sm sm:mx-6"
                    >
                        {errors.session}
                    </p>
                )}
                {children}
            </div>
        </div>
    );
}
