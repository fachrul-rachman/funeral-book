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

// Status is always shown with its text label; color only reinforces it.
export const statusStyles: Record<string, { dot: string; badge: string }> = {
    new_request: {
        dot: 'bg-warning-fill',
        badge: 'border-warning/50 bg-warning-soft text-warning',
    },
    contacted: {
        dot: 'bg-[#8FB8DC]',
        badge: 'border-transparent bg-[#8FB8DC] text-navy',
    },
    in_progress: {
        dot: 'bg-[#526B9F]',
        badge: 'border-transparent bg-[#526B9F] text-white',
    },
    completed: {
        dot: 'bg-success',
        badge: 'border-transparent bg-success text-white',
    },
    cancelled: {
        dot: 'bg-[#8B919D]',
        badge: 'border-navy/20 bg-[#E4E6EA] text-navy',
    },
};

export const roleLabels: Record<string, string> = {
    superadmin: 'Superadmin',
    admin: 'Admin',
    manager: 'Manager',
    pic: 'PIC',
};

const siteLabels: Record<string, string> = {
    karawang: 'Karawang',
    tangerang: 'Tangerang',
};

export function siteLabel(site: string | null) {
    return site ? (siteLabels[site] ?? site) : 'Lokasi belum ditentukan';
}

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
export const secondaryButtonClass =
    'inline-flex min-h-11 items-center gap-2 rounded-md border border-navy/40 px-4 py-2 font-medium hover:bg-navy/5 disabled:opacity-60';
export const dangerButtonClass =
    'inline-flex min-h-11 items-center gap-2 rounded-md bg-danger px-4 py-2 font-semibold text-white hover:bg-danger/90 disabled:opacity-60';
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

function instant(value: string) {
    const iso = value.includes(' ') ? value.replace(' ', 'T') : value;
    const explicit = /(?:Z|[+-]\d\d:\d\d)$/.test(iso)
        ? iso
        : /[+-]\d\d$/.test(iso)
          ? iso + ':00'
          : iso + 'Z';
    return new Date(explicit);
}

export function dateTime(value: string | null) {
    if (!value) return 'Belum ada data';
    const date = instant(value);
    return (
        dateFormat.format(date) +
        ', ' +
        timeFormat.format(date).replace(':', '.') +
        ' WIB'
    );
}

export function timeOnly(value: string) {
    return timeFormat.format(instant(value)).replace(':', '.');
}

// `day` is a plain YYYY-MM-DD calendar date (no timezone conversion).
export function longDate(day: string) {
    return new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(day + 'T00:00:00Z'));
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
