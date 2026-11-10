import { Head, useForm } from '@inertiajs/react';
import { Save, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ConfirmDialog from '../components/confirm-dialog';
import Notice from '../components/notice';
import PasswordInput from '../components/password-input';
import PhoneInput from '../components/phone-input';
import StaffLayout from '../components/staff-layout';
import {
    buttonClass,
    fieldClass,
    roleLabels,
    secondaryButtonClass,
} from '../lib/funeral';

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
    onClose,
}: {
    account?: Account;
    roles: string[];
    onClose: () => void;
}) {
    const form = useForm({
        name: account?.name ?? '',
        phone: account?.phone ?? '',
        role: account?.role ?? 'pic',
        active: account?.active ?? true,
        password: '',
    });
    const [confirming, setConfirming] = useState(false);

    function save() {
        if (account) {
            form.patch(`/staf/pengguna/${account.id}`, {
                onFinish: () => form.reset('password'),
            });
        } else {
            form.post('/staf/pengguna', {
                onSuccess: onClose,
                onError: () => form.reset('password'),
            });
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (account?.active && !form.data.active) setConfirming(true);
        else save();
    }

    return (
        <form
            onSubmit={submit}
            className="max-w-xl min-w-0 space-y-4 rounded-lg border border-navy/20 p-5"
        >
            <h2 className="flex items-center gap-2 font-semibold break-words">
                {!account && <UserPlus className="size-5" aria-hidden="true" />}
                {account ? 'Ubah akun ' + account.name : 'Tambah akun'}
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
                            {roleLabels[role] ?? role}
                        </option>
                    ))}
                </select>
            </label>
            <label className="flex min-h-11 items-center gap-2">
                <input
                    type="checkbox"
                    className="size-5"
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
                <Notice key={error} tone="danger">
                    {error}
                </Notice>
            ))}
            {form.recentlySuccessful && (
                <Notice tone="success">Perubahan tersimpan.</Notice>
            )}
            <div className="flex flex-wrap gap-2">
                <button className={buttonClass} disabled={form.processing}>
                    <Save className="size-4" aria-hidden="true" /> Simpan
                </button>
                <button
                    type="button"
                    className={secondaryButtonClass}
                    onClick={onClose}
                >
                    {account ? 'Tutup' : 'Batal'}
                </button>
            </div>
            {confirming && (
                <ConfirmDialog
                    title="Nonaktifkan akun ini?"
                    confirmLabel="Ya, nonaktifkan"
                    danger
                    onCancel={() => setConfirming(false)}
                    onConfirm={() => {
                        setConfirming(false);
                        save();
                    }}
                >
                    {account?.name} tidak akan bisa masuk lagi dan semua sesi
                    aktifnya berakhir sampai akun diaktifkan kembali.
                </ConfirmDialog>
            )}
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
    const [editing, setEditing] = useState<string | 'new' | null>(null);
    return (
        <StaffLayout>
            <main className="space-y-5 p-4 sm:p-7 xl:p-9">
                <Head title="Pengguna" />
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-semibold">Pengguna</h1>
                        <p className="mt-1 text-navy/75">
                            Kelola akun dan akses staf.
                        </p>
                    </div>
                    {editing !== 'new' && (
                        <button
                            type="button"
                            className={buttonClass}
                            onClick={() => setEditing('new')}
                        >
                            <UserPlus className="size-4" aria-hidden="true" />{' '}
                            Tambah akun
                        </button>
                    )}
                </header>
                {editing === 'new' && (
                    <AccountForm
                        roles={roles}
                        onClose={() => setEditing(null)}
                    />
                )}
                <ul className="divide-y divide-navy/15 rounded-lg border border-navy/20">
                    {users.map((account) => (
                        <li key={account.id} className="px-4 py-3">
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium break-words">
                                        {account.name}
                                    </p>
                                    <p className="text-sm text-navy/75">
                                        {account.phone}
                                    </p>
                                </div>
                                <p className="text-sm">
                                    {roleLabels[account.role] ?? account.role}
                                </p>
                                <span
                                    className={
                                        'inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold ' +
                                        (account.active
                                            ? 'border-success/40 bg-success-soft text-success'
                                            : 'border-navy/20 bg-[#E4E6EA] text-navy')
                                    }
                                >
                                    {account.active ? 'Aktif' : 'Nonaktif'}
                                </span>
                                {editing !== account.id && (
                                    <button
                                        type="button"
                                        className={secondaryButtonClass}
                                        aria-label={'Ubah ' + account.name}
                                        onClick={() => setEditing(account.id)}
                                    >
                                        Ubah
                                    </button>
                                )}
                            </div>
                            {editing === account.id && (
                                <div className="mt-3">
                                    <AccountForm
                                        key={account.id}
                                        account={account}
                                        roles={roles}
                                        onClose={() => setEditing(null)}
                                    />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </main>
        </StaffLayout>
    );
}
