import { statuses, statusStyles } from '../lib/funeral';

export default function StatusBadge({ status }: { status: string }) {
    return (
        <span
            className={
                'inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold whitespace-nowrap ' +
                statusStyles[status].badge
            }
        >
            {statuses[status]}
        </span>
    );
}
