import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import PasswordInput from '../components/password-input';
import PhoneInput from '../components/phone-input';
import { buttonClass } from '../lib/funeral';

export default function Login() {
    const form = useForm({ phone: '', password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/masuk', { onFinish: () => form.reset('password') });
    }

    return (
        <main className="min-h-dvh lg:grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <Head title="Masuk staf" />
            <aside className="flex flex-col justify-between gap-8 border-b-4 border-gold bg-navy px-6 py-8 text-white sm:px-10 lg:border-r-4 lg:border-b-0 lg:p-12 xl:p-18">
                <p className="text-sm font-semibold tracking-wide">
                    Lestari Memorial Park
                </p>
                <div className="space-y-3">
                    <ShieldCheck
                        className="size-8 text-gold"
                        aria-hidden="true"
                    />
                    <h2 className="text-2xl font-semibold lg:text-4xl">
                        Area staf
                    </h2>
                    <p className="max-w-sm text-white/85">
                        Kelola pengajuan dan jadwal layanan pemakaman.
                    </p>
                </div>
                <Link
                    href="/"
                    className="inline-flex w-fit items-center gap-2 text-sm underline underline-offset-4"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" /> Kembali
                    ke formulir publik
                </Link>
            </aside>
            <div className="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-16">
                <section className="w-full max-w-lg space-y-6">
                    <div>
                        <h1 className="text-3xl font-semibold sm:text-4xl">
                            Masuk staf
                        </h1>
                        <p className="mt-2 text-navy/80">
                            Gunakan nomor WhatsApp akun staf Anda.
                        </p>
                    </div>
                    <form onSubmit={submit} className="space-y-5">
                        <label className="block space-y-1 font-medium">
                            <span>Nomor telepon</span>
                            <PhoneInput
                                autoComplete="username"
                                required
                                value={form.data.phone}
                                onValueChange={(value) =>
                                    form.setData('phone', value)
                                }
                            />
                        </label>
                        <PasswordInput
                            label="Kata sandi"
                            autoComplete="current-password"
                            maxLength={72}
                            required
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                        />
                        {Object.values(form.errors).map((error) => (
                            <p
                                key={error}
                                role="alert"
                                className="rounded-md border border-gold bg-gold/10 p-3"
                            >
                                {error}
                            </p>
                        ))}
                        <button
                            className={buttonClass + ' w-full justify-center'}
                            disabled={form.processing}
                        >
                            {form.processing ? 'Sedang masuk…' : 'Masuk'}{' '}
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </button>
                    </form>
                    <p className="text-sm text-navy/75">
                        Butuh akses? Hubungi Superadmin.
                    </p>
                </section>
            </div>
        </main>
    );
}
