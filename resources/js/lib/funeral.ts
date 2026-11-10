export const statuses: Record<string, string> = {
    new_request: 'Pengajuan baru',
    contacted: 'Sudah dihubungi',
    in_progress: 'Sedang diproses',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

export function visitationCorrectionMessage(fields: string[]): string {
    const labels: Record<string, string> = {
        'burial.site': 'Lokasi makam',
        'burial.zone': 'Zona',
        'burial.lot_number': 'Nomor lahan',
    };
    return (
        fields
            .map((field) => labels[field])
            .filter(Boolean)
            .join(', ') +
        ' tidak cocok dengan data aplikasi ziarah. Pengajuan dikembalikan ke Sedang diproses. Perbaiki bagian Makam dan pembayaran, simpan, lalu selesaikan kembali.'
    );
}

export const statusColors: Record<string, string> = {
    new_request: 'bg-gold',
    contacted: 'bg-[#8FB8DC]',
    in_progress: 'bg-[#526B9F]',
    completed: 'bg-navy',
    cancelled: 'bg-[#8B919D]',
};

export const documentLabels: Record<string, string> = {
    contact_evidence: 'Foto bukti kontak',
    ownership_certificate: 'Sertifikat kepemilikan makam',
    plot_owner_ktp: 'KTP pemilik makam',
    deceased_ktp: 'KTP almarhum/almarhumah',
    hospital_death_certificate: 'Surat kematian rumah sakit',
    embassy_death_certificate: 'Surat kematian kedutaan',
};

export const fieldClass =
    'block min-h-11 w-full min-w-0 rounded-md border border-navy/55 bg-white px-3 py-2 text-navy disabled:bg-navy/5';
export const invalidFieldClass = fieldClass.replace(
    'border-navy/55',
    'border-danger ring-1 ring-danger',
);
export const helpWhatsApp = {
    href: 'https://wa.me/6287788088820',
    label: '+62 877-8808-8820',
};
export const buttonClass =
    'inline-flex min-h-11 items-center gap-2 rounded-md bg-navy px-4 py-2 font-semibold text-white hover:bg-navy/90 disabled:opacity-60';
export const linkClass =
    'font-medium text-navy underline decoration-gold underline-offset-4';

const dateFormat = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const timeFormat = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

export function dateOnly(value: string) {
    return dateFormat.format(new Date(value + 'T00:00:00+07:00'));
}

export function dateTime(value: string | null) {
    if (!value) return 'Belum ada data';
    const instant = value.includes(' ') ? value.replace(' ', 'T') : value;
    const explicit = /(?:Z|[+-]\d\d:\d\d)$/.test(instant)
        ? instant
        : /[+-]\d\d$/.test(instant)
          ? instant + ':00'
          : instant + 'Z';
    const date = new Date(explicit);
    return (
        dateFormat.format(date) +
        ', ' +
        timeFormat.format(date).replace(':', '.') +
        ' WIB'
    );
}

export function duration(seconds: number | null) {
    if (seconds === null) return 'Belum ada data';
    if (seconds < 60) return seconds + ' detik';
    const minutes = Math.round(seconds / 60);
    return minutes < 60
        ? minutes + ' menit'
        : Math.floor(minutes / 60) + ' jam ' + (minutes % 60) + ' menit';
}

export type Pagination<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    current_page: number;
    last_page: number;
};
