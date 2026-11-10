import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';

const tones = {
    danger: {
        box: 'border-danger bg-danger-soft',
        title: 'text-danger',
        Icon: CircleAlert,
    },
    warning: {
        box: 'border-warning bg-warning-soft',
        title: 'text-warning',
        Icon: TriangleAlert,
    },
    success: {
        box: 'border-success/40 bg-success-soft',
        title: 'text-success',
        Icon: CircleCheck,
    },
    info: { box: 'border-navy/25 bg-white', title: 'text-navy', Icon: Info },
};

export default function Notice({
    tone,
    title,
    children,
    className = '',
}: {
    tone: keyof typeof tones;
    title?: string;
    children?: ReactNode;
    className?: string;
}) {
    const { box, title: titleClass, Icon } = tones[tone];
    return (
        <div
            role={tone === 'info' || tone === 'success' ? 'status' : 'alert'}
            className={'rounded-md border p-3 text-sm ' + box + ' ' + className}
        >
            <div className="flex items-start gap-2">
                <Icon
                    className={'mt-0.5 size-4 shrink-0 ' + titleClass}
                    aria-hidden="true"
                />
                <div className="min-w-0 space-y-1 break-words">
                    {title && (
                        <p className={'font-semibold ' + titleClass}>{title}</p>
                    )}
                    {children}
                </div>
            </div>
        </div>
    );
}
