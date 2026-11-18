import { usePoll } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import Notice from './notice';
import { buttonClass, secondaryButtonClass } from '../lib/funeral';

export type CompletionState =
    | 'pending'
    | 'accepted'
    | 'failed'
    | 'unknown'
    | null;

export default function CompletionProgress({
    state,
}: {
    state: CompletionState;
}) {
    const [issue, setIssue] = useState<string | null>(null);
    const { start, stop } = usePoll(
        2000,
        {
            only: [
                'request',
                'canEdit',
                'canProcess',
                'canTransfer',
                'handlers',
                'visitation',
                'completionState',
                'notificationFailures',
                'activity',
            ],
            onNetworkError: () => {
                setIssue(
                    'Koneksi terputus. Hasil proses belum dapat dipastikan.',
                );
                return false;
            },
            onHttpException: () => {
                setIssue(
                    'Status terbaru belum dapat diperiksa. Proses di server mungkin masih berjalan.',
                );
                return false;
            },
        },
        { autoStart: false, mode: 'rest', background: 'pause' },
    );

    useEffect(() => {
        if (state !== 'pending' || issue) return;
        start();
        const timer = window.setTimeout(() => {
            setIssue(
                'Proses membutuhkan waktu lebih lama. Hasilnya belum dapat dipastikan.',
            );
        }, 120_000);
        return () => {
            window.clearTimeout(timer);
            stop();
        };
    }, [state, issue, start, stop]);

    if (state === null) return null;
    if (state === 'pending') {
        if (issue) {
            return (
                <Notice tone="warning">
                    <p>{issue}</p>
                    <button
                        type="button"
                        className={secondaryButtonClass + ' mt-2'}
                        onClick={() => setIssue(null)}
                    >
                        Periksa status
                    </button>
                </Notice>
            );
        }
        return (
            <button
                type="button"
                className={buttonClass + ' w-full justify-center'}
                disabled
                aria-busy="true"
            >
                <LoaderCircle
                    className="size-4 motion-safe:animate-spin"
                    aria-hidden="true"
                />
                Memproses…
            </button>
        );
    }
    if (state === 'accepted') {
        return (
            <Notice tone="success" title="Pengajuan selesai diproses">
                Permintaan pengiriman pesan customer telah diterima WhatsApp.
            </Notice>
        );
    }
    return (
        <Notice tone={state === 'failed' ? 'danger' : 'warning'}>
            {state === 'failed'
                ? 'Proses penyelesaian belum berhasil. Periksa kendala di bawah.'
                : 'Hasil pengiriman pesan customer belum dapat dipastikan. Periksa status sebelum mencoba lagi.'}
        </Notice>
    );
}
