import { CircleAlert } from 'lucide-react';
import type { ReactNode } from 'react';

export default function FieldError({
    id,
    children,
}: {
    id?: string;
    children: ReactNode;
}) {
    return (
        <p
            id={id}
            className="mt-1.5 flex items-start gap-1.5 text-sm font-medium text-danger"
        >
            <CircleAlert
                className="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <span>{children}</span>
        </p>
    );
}
