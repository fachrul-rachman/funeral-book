import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import FieldError from '../components/field-error';
import Notice from '../components/notice';
import PasswordInput from '../components/password-input';
import PhoneInput from '../components/phone-input';
import { buttonClass, linkClass } from '../lib/funeral';
import { validPhone } from '../lib/phone';

type Problems = { phone?: string; password?: string };

export default function Login() {
    const form = useForm({ phone: '', password: '' });
    const [problems, setProblems] = useState<Problems>({});

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formElement = event.currentTarget;
        const found: Problems = {};
        if (!form.data.phone.trim())
            found.phone = 'Isi nomor WhatsApp akun Anda.';
        else if (!validPhone(form.data.phone))
            found.phone =
                'Masukkan nomor telepon yang valid, misalnya 081234567890.';
        if (!form.data.password) found.password = 'Isi kata sandi.';
        setProblems(found);
        if (Object.keys(found).length > 0) {
            requestAnimationFrame(() =>
                formElement
                    .querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus(),
            );
            return;
        }
        form.post('/masuk', { onFinish: () => form.reset('password') });
    }

    return (
        <main className="min-h-dvh lg:grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <Head title="Masuk staf" />
            <aside className="flex flex-col justify-center bg-navy px-6 py-4 text-white sm:px-10 lg:p-12 xl:p-18">
                <p className="text-lg font-semibold lg:text-4xl">Area staf</p>
                <p className="mt-3 hidden max-w-sm text-white/85 lg:block">
                    Kelola pengajuan dan jadwal layanan pemakaman.
                </p>
            </aside>
            <div className="flex items-center justify-center px-5 py-8 sm:px-10 lg:px-16">
                <section className="w-full max-w-lg space-y-6">
                    <img
                        src="/LESTARI.png"
                        alt="Lestari Memorial Park"
                        width={1996}
                        height={675}
                        className="h-12 w-auto"
                    />
                    <div>
                        <h1 className="text-3xl font-semibold sm:text-4xl">
                            Masuk staf
                        </h1>
                        <p className="mt-2 text-navy/80">
                            Gunakan nomor WhatsApp akun staf Anda.
                        </p>
                    </div>
                    <form noValidate onSubmit={submit} className="space-y-5">
                        <div>
                            <label
                                htmlFor="login-phone"
                                className="block font-medium"
                            >
                                Nomor WhatsApp
                            </label>
                            <p
                                id="login-phone-hint"
                                className="mt-0.5 text-sm text-navy/75"
                            >
                                Contoh: 081234567890
                            </p>
                            <div className="mt-1">
                                <PhoneInput
                                    id="login-phone"
                                    autoComplete="username"
                                    required
                                    invalid={!!problems.phone}
                                    aria-describedby={
                                        'login-phone-hint' +
                                        (problems.phone
                                            ? ' login-phone-error'
                                            : '')
                                    }
                                    value={form.data.phone}
                                    onValueChange={(value) => {
                                        setProblems((previous) => ({
                                            ...previous,
                                            phone: undefined,
                                        }));
                                        form.setData('phone', value);
                                    }}
                                />
                            </div>
                            {problems.phone && (
                                <FieldError id="login-phone-error">
                                    {problems.phone}
                                </FieldError>
                            )}
                        </div>
                        <div>
                            <PasswordInput
                                label="Kata sandi"
                                autoComplete="current-password"
                                maxLength={72}
                                required
                                invalid={!!problems.password}
                                aria-describedby={
                                    problems.password
                                        ? 'login-password-error'
                                        : undefined
                                }
                                value={form.data.password}
                                onChange={(event) => {
                                    setProblems((previous) => ({
                                        ...previous,
                                        password: undefined,
                                    }));
                                    form.setData(
                                        'password',
                                        event.target.value,
                                    );
                                }}
                            />
                            {problems.password && (
                                <FieldError id="login-password-error">
                                    {problems.password}
                                </FieldError>
                            )}
                        </div>
                        {Object.values(form.errors).map((error) => (
                            <Notice key={error} tone="danger">
                                {error}
                            </Notice>
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
                        Lupa kata sandi atau butuh akses? Hubungi Superadmin.
                    </p>
                    <Link
                        href="/"
                        className={
                            linkClass +
                            ' inline-flex items-center gap-2 text-sm'
                        }
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />{' '}
                        Kembali ke formulir publik
                    </Link>
                </section>
            </div>
        </main>
    );
}
