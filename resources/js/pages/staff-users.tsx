import { Head, useForm } from '@inertiajs/react';
import { Save, UserPlus } from 'lucide-react';
import StaffLayout from '../components/staff-layout';
import PasswordInput from '../components/password-input';
import PhoneInput from '../components/phone-input';
import { buttonClass, fieldClass } from '../lib/funeral';
import type { FormEvent } from 'react';

type Account = {
    id: string;
    name: string;
    phone: string;
    role: string;
    active: boolean;
};

const inputClass = fieldClass + ' mt-1';

function AccountForm({
    account,
    roles,
}: {
    account?: Account;
    roles: string[];
}) {
    const form = useForm({
        name: account?.name ?? '',
        phone: account?.phone ?? '',
        role: account?.role ?? 'pic',
        active: account?.active ?? true,
        password: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (account) {
            form.patch(`/staf/pengguna/${account.id}`, {
                onFinish: () => form.reset('password'),
            });
        } else {
            form.post('/staf/pengguna', {
                onSuccess: () => form.reset(),
                onError: () => form.reset('password'),
            });
        }
    }

    return (
        <form
            onSubmit={submit}
            className="min-w-0 space-y-4 rounded-lg border border-navy/20 p-5"
        >
            <h2 className="flex items-center gap-2 border-b border-gold pb-3 font-semibold break-words">
                {!account && <UserPlus className="size-5" aria-hidden="true" />}
                {account ? account.name : 'Tambah akun'}
            </h2>
            {!account && (
                <label className="block">
                    Nama
                    <input
                        className={inputClass}
                        required
                        maxLength={120}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                </label>
            )}
            <label className="block">
                Nomor WhatsApp
                <PhoneInput
                    required
                    autoComplete="tel"
                    value={form.data.phone}
                    onValueChange={(value) => form.setData('phone', value)}
                />
            </label>
            <label className="block">
                Peran
                <select
                    className={inputClass}
                    value={form.data.role}
                    onChange={(event) =>
                        form.setData('role', event.target.value)
                    }
                >
                    {roles.map((role) => (
                        <option key={role} value={role}>
                            {role}
                        </option>
                    ))}
                </select>
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(event) =>
                        form.setData('active', event.target.checked)
                    }
                />{' '}
                Akun aktif
            </label>
            <PasswordInput
                label={
                    account
                        ? 'Kata sandi baru (opsional)'
                        : 'Kata sandi (minimal 8 karakter)'
                }
                minLength={8}
                maxLength={72}
                autoComplete="new-password"
                required={!account}
                value={form.data.password}
                onChange={(event) =>
                    form.setData('password', event.target.value)
                }
            />
            {Object.values(form.errors).map((error) => (
                <p key={error} role="alert" className="text-navy">
                    {error}
                </p>
            ))}
            {form.recentlySuccessful && (
                <p role="status">Perubahan tersimpan.</p>
            )}
            <button className={buttonClass} disabled={form.processing}>
                <Save className="size-4" aria-hidden="true" /> Simpan
            </button>
        </form>
    );
}

export default function StaffUsers({
    users,
    roles,
}: {
    users: Account[];
    roles: string[];
}) {
    return (
        <StaffLayout>
            <main className="space-y-5 p-4 sm:p-7 xl:p-9">
                <Head title="Pengguna" />
                <header className="border-b border-gold pb-4">
                    <h1 className="text-3xl font-semibold">Pengguna</h1>
                    <p className="mt-1 text-navy/75">
                        Kelola akun dan akses staf.
                    </p>
                </header>
                <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <AccountForm roles={roles} />
                    {users.map((account) => (
                        <AccountForm
                            key={account.id}
                            account={account}
                            roles={roles}
                        />
                    ))}
                </div>
            </main>
        </StaffLayout>
    );
}
