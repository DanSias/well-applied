import { relativeTimeFromNow } from '@/lib/relative-time';

/** A relative time for quick scanning, with the exact time on hover and for assistive technology. */
export function Time({ at }: { at: string | null }) {
    if (at === null) {
        return <span>—</span>;
    }

    const exact = new Date(at).toLocaleString();

    return (
        <time dateTime={at} title={exact}>
            {relativeTimeFromNow(at)}
            <span className="sr-only"> ({exact})</span>
        </time>
    );
}
