import { useForm } from '@inertiajs/react';
import { FileText, Image as ImageIcon, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { buttonClass, documentLabels, linkClass } from '../lib/funeral';

export type RequestDocument = {
    id: string;
    type: string;
    original_file_name: string;
    validation_state: string;
    detected_mime: string;
};

const images = ['image/jpeg', 'image/png', 'image/webp'];
const canPreview = (document: RequestDocument) =>
    images.includes(document.detected_mime) ||
    document.detected_mime === 'application/pdf';

export function FilePicker({
    label,
    accept,
    file,
    disabled,
    onSelect,
}: {
    label: string;
    accept: string;
    file: File | null;
    disabled: boolean;
    onSelect: (file: File) => void;
}) {
    return (
        <div className="min-w-0 space-y-1.5">
            <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-navy/25 px-3 text-sm font-medium focus-within:outline-2 focus-within:outline-offset-2 has-disabled:opacity-60">
                <Upload className="size-4 shrink-0" aria-hidden="true" />{' '}
                {label}
                <input
                    className="sr-only"
                    type="file"
                    disabled={disabled}
                    accept={accept}
                    onChange={(event) => {
                        const selected = event.currentTarget.files?.[0];
                        if (selected) onSelect(selected);
                        event.currentTarget.value = '';
                    }}
                />
            </label>
            <p className="text-xs text-navy/70">Maksimal 5 MiB</p>
            {file && (
                <p className="text-sm break-all" role="status">
                    {file.name}
                </p>
            )}
        </div>
    );
}

function Preview({
    document,
    url,
    onClose,
}: {
    document: RequestDocument;
    url: string;
    onClose: () => void;
}) {
    const modal = useRef<HTMLDialogElement>(null);
    const [source, setSource] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const dialog = modal.current;
        dialog?.showModal();
        const abort = new AbortController();
        let objectUrl: string | undefined;
        void (async () => {
            try {
                const response = await fetch(url, {
                    signal: abort.signal,
                    cache: 'no-store',
                });
                if (
                    !response.ok ||
                    response.redirected ||
                    response.headers.get('Content-Type') !==
                        'application/octet-stream'
                )
                    throw Error();
                const bytes = await response.arrayBuffer();
                if (abort.signal.aborted) return;
                objectUrl = URL.createObjectURL(
                    new Blob([bytes], { type: document.detected_mime }),
                );
                setSource(objectUrl);
            } catch {
                if (!abort.signal.aborted) setFailed(true);
            }
        })();

        return () => {
            abort.abort();
            dialog?.close();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [document.detected_mime, url]);

    return (
        <dialog
            ref={modal}
            aria-labelledby="preview-title"
            className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-4xl rounded-lg border border-navy/15 bg-white p-0 text-navy shadow-lg backdrop:bg-navy/50"
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
        >
            <header className="flex items-start justify-between gap-3 border-b border-navy/15 p-4">
                <h2
                    id="preview-title"
                    className="min-w-0 font-semibold break-all"
                >
                    {document.original_file_name}
                </h2>
                <button
                    type="button"
                    aria-label="Tutup pratinjau"
                    className="shrink-0 rounded-md p-2 hover:bg-navy/5"
                    onClick={onClose}
                >
                    <X className="size-5" aria-hidden="true" />
                </button>
            </header>
            <div className="p-4">
                {failed ? (
                    <p role="alert">Pratinjau tidak dapat dibuka.</p>
                ) : source ? (
                    images.includes(document.detected_mime) ? (
                        <img
                            src={source}
                            alt={document.original_file_name}
                            className="mx-auto max-h-[70dvh] max-w-full object-contain"
                        />
                    ) : (
                        <iframe
                            title={'Pratinjau ' + document.original_file_name}
                            src={source}
                            referrerPolicy="no-referrer"
                            className="h-[66dvh] w-full border-0"
                        />
                    )
                ) : (
                    <p role="status">Memuat pratinjau…</p>
                )}
            </div>
            <footer className="border-t border-navy/15 p-4">
                <a href={url} className={linkClass}>
                    Unduh berkas
                </a>
            </footer>
        </dialog>
    );
}

function DocumentRow({
    type,
    items,
    canEdit,
    url,
    preview,
}: {
    type: string;
    items: RequestDocument[];
    canEdit: boolean;
    url: string;
    preview: (document: RequestDocument) => void;
}) {
    const form = useForm({ type, document: null as File | null });
    const label = documentLabels[type];
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(url + '/dokumen', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('document'),
        });
    }

    return (
        <li className="grid items-start gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto]">
            <div className="min-w-0 space-y-2">
                <h3 className="text-sm font-semibold">{label}</h3>
                {!items.length && (
                    <p className="text-sm text-navy/70">Belum diunggah</p>
                )}
                {items.map((document) => {
                    const accepted = document.validation_state === 'accepted';
                    return (
                        <div
                            key={document.id}
                            className="flex flex-wrap items-center gap-2 sm:flex-nowrap"
                        >
                            {accepted &&
                            images.includes(document.detected_mime) ? (
                                <img
                                    src={
                                        url +
                                        '/dokumen/' +
                                        document.id +
                                        '?thumbnail=1'
                                    }
                                    alt=""
                                    loading="lazy"
                                    width="40"
                                    height="40"
                                    className="size-10 shrink-0 rounded border border-navy/15 object-cover"
                                />
                            ) : (
                                <span className="flex size-10 shrink-0 items-center justify-center rounded bg-navy/5">
                                    {images.includes(document.detected_mime) ? (
                                        <ImageIcon
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <FileText
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    )}
                                </span>
                            )}
                            <div className="min-w-0 flex-1 text-sm break-all">
                                <p>{document.original_file_name}</p>
                                {!accepted && (
                                    <p className="text-xs text-navy/70">
                                        {document.validation_state === 'pending'
                                            ? 'Menunggu pemeriksaan'
                                            : 'Ditolak'}
                                    </p>
                                )}
                            </div>
                            {accepted && canPreview(document) && (
                                <button
                                    type="button"
                                    className={
                                        linkClass + ' min-h-10 px-2 text-sm'
                                    }
                                    aria-label={'Lihat ' + label}
                                    onClick={() => preview(document)}
                                >
                                    Lihat
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
            {canEdit && type !== 'contact_evidence' && (
                <form
                    onSubmit={submit}
                    className="min-w-0 space-y-2 sm:max-w-48"
                >
                    <FilePicker
                        label={
                            form.data.document
                                ? 'Pilih berkas'
                                : items.length
                                  ? 'Ganti'
                                  : 'Unggah'
                        }
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                        file={form.data.document}
                        disabled={form.processing}
                        onSelect={(file) => {
                            form.clearErrors();
                            if (file.size > 5 * 1024 * 1024) {
                                form.setError(
                                    'document',
                                    'Ukuran berkas melebihi 5 MiB.',
                                );
                                return;
                            }
                            form.setData('document', file);
                        }}
                    />
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="text-sm text-red-900">
                            {Object.values(form.errors).join(' ')}
                        </p>
                    )}
                    {form.data.document && (
                        <div className="flex flex-wrap gap-2">
                            <button
                                className={buttonClass}
                                disabled={form.processing}
                            >
                                Simpan berkas
                            </button>
                            <button
                                type="button"
                                disabled={form.processing}
                                className={linkClass + ' px-2'}
                                onClick={() => {
                                    form.reset('document');
                                    form.clearErrors();
                                }}
                            >
                                Batal
                            </button>
                        </div>
                    )}
                </form>
            )}
        </li>
    );
}

export default function StaffRequestDocuments({
    documents,
    canEdit,
    url,
}: {
    documents: RequestDocument[];
    canEdit: boolean;
    url: string;
}) {
    const [preview, setPreview] = useState<RequestDocument | null>(null);
    return (
        <section
            id="dokumen"
            className="order-3 min-w-0 rounded-lg border border-navy/15 bg-white p-4 sm:p-5"
        >
            <h2 className="flex items-center gap-2 text-lg font-semibold">
                <FileText className="size-5" aria-hidden="true" /> Dokumen
            </h2>
            <ul className="mt-2 divide-y divide-navy/15">
                {Object.keys(documentLabels).map((type) => {
                    const items = documents.filter(
                        (document) => document.type === type,
                    );
                    return type === 'contact_evidence' &&
                        items.length === 0 ? null : (
                        <DocumentRow
                            key={type}
                            type={type}
                            items={items}
                            canEdit={canEdit}
                            url={url}
                            preview={setPreview}
                        />
                    );
                })}
            </ul>
            {preview && (
                <Preview
                    key={preview.id}
                    document={preview}
                    url={url + '/dokumen/' + preview.id}
                    onClose={() => setPreview(null)}
                />
            )}
        </section>
    );
}
