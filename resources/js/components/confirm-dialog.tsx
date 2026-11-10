import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import {
    buttonClass,
    dangerButtonClass,
    secondaryButtonClass,
} from '../lib/funeral';

// Mount it only while a confirmation is pending; "Batal" is first in focus order.
export default function ConfirmDialog({
    title,
    children,
    confirmLabel,
    danger = false,
    busy = false,
    onConfirm,
    onCancel,
}: {
    title: string;
    children: ReactNode;
    confirmLabel: string;
    danger?: boolean;
    busy?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    const modal = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = modal.current;
        dialog?.showModal();
        return () => dialog?.close();
    }, []);

    return (
        <dialog
            ref={modal}
            aria-labelledby="confirm-title"
            className="m-auto w-[calc(100%_-_2rem)] max-w-md rounded-lg border border-navy/15 bg-white p-0 text-navy backdrop:bg-navy/50"
            onCancel={(event) => {
                event.preventDefault();
                onCancel();
            }}
        >
            <div className="space-y-3 p-5">
                <h2 id="confirm-title" className="text-lg font-semibold">
                    {title}
                </h2>
                <div className="text-sm text-navy/80">{children}</div>
            </div>
            <div className="flex flex-wrap justify-end gap-2 border-t border-navy/15 p-4">
                <button
                    type="button"
                    className={secondaryButtonClass}
                    onClick={onCancel}
                >
                    Batal
                </button>
                <button
                    type="button"
                    className={danger ? dangerButtonClass : buttonClass}
                    disabled={busy}
                    onClick={onConfirm}
                >
                    {confirmLabel}
                </button>
            </div>
        </dialog>
    );
}
