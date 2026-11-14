import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/surface';
import { TextInput } from '@/components/ui/field';
import { Pagination } from '@/components/ui/pagination';
import { Select } from '@/components/ui/select';
import AppShell from '@/layouts/app-shell';
import { relativeTimeFromNow } from '@/lib/relative-time';
import { home } from '@/routes';
import { index as rejectionsIndex } from '@/routes/discovery/rejections';
import type {
    DiscoveryRejectionRow,
    DiscoveryRejectionsProps,
} from '@/types/discovery-rejection';

/** Pause between the last keystroke and the search hitting the server. */
const SEARCH_DEBOUNCE_MS = 300;

function params(
    q: string,
    reason: string,
    page: number,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (q !== '') {
        query.q = q;
    }
    if (reason !== 'all') {
        query.reason = reason;
    }
    if (page > 1) {
        query.page = page;
    }

    return query;
}

function RejectionRow({ row }: { row: DiscoveryRejectionRow }) {
    const detail = [row.location, row.remote_status]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className="px-5 py-3.5">
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                <div className="min-w-0 flex-1 basis-72">
                    <p className="text-fg text-sm font-medium break-words">
                        {row.title}
                        <span className="text-fg-muted font-normal">
                            {' '}
                            · {row.company}
                        </span>
                    </p>
                    <p className="text-fg-subtle mt-0.5 text-xs break-words">
                        {detail !== '' ? detail : 'No location given'}
                    </p>
                </div>
                <p className="text-fg-subtle flex flex-wrap items-center gap-2 text-xs">
                    <Badge>{row.provider}</Badge>
                    <Badge tone="warn">{row.reason.label}</Badge>
                </p>
            </div>
            <p className="text-fg-muted mt-1.5 text-xs break-words">
                {row.matched_term ? (
                    <>
                        Matched{' '}
                        <span className="text-fg font-medium">
                            &ldquo;{row.matched_term}&rdquo;
                        </span>{' '}
                        in {row.matched_field}
                    </>
                ) : (
                    row.matched_field && <>Checked {row.matched_field}</>
                )}
                {row.evidence && <span> — {row.evidence}</span>}
            </p>
            <p className="text-fg-subtle mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span title={row.last_seen_at}>
                    Last seen {relativeTimeFromNow(row.last_seen_at)}
                </span>
                {row.seen_count > 1 && <span>Seen {row.seen_count} times</span>}
                {row.source_url && (
                    <a
                        href={row.source_url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                    >
                        View posting
                    </a>
                )}
            </p>
        </li>
    );
}

export default function DiscoveryRejections({
    rejections,
    filters,
    reasons,
    pagination,
}: DiscoveryRejectionsProps) {
    const [q, setQ] = useState(filters.q);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Drop a pending search if the page unmounts mid-debounce.
    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    const visit = (nextQ: string, reason: string, page: number) => {
        router.get(rejectionsIndex.url(), params(nextQ, reason, page), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const onSearch = (value: string) => {
        setQ(value);
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(
            () => visit(value.trim(), filters.reason, 1),
            SEARCH_DEBOUNCE_MS,
        );
    };

    const filtered = filters.q !== '' || filters.reason !== 'all';

    return (
        <AppShell>
            <Head title="Filtered candidates" />

            <header>
                <p className="text-fg-subtle text-xs">
                    <Link
                        href={home.url()}
                        className="hover:text-fg underline-offset-2 hover:underline"
                    >
                        Overview
                    </Link>{' '}
                    / Discovery
                </p>
                <h1 className="text-fg mt-1 text-xl font-semibold tracking-tight sm:text-2xl">
                    Filtered candidates
                </h1>
                <p className="text-fg-muted mt-0.5 max-w-2xl text-sm">
                    Postings discovery found but did not add to your Jobs inbox,
                    and why. These are not jobs: nothing here can be qualified
                    or pursued. Records are kept for 30 days after they were
                    last seen.
                </p>
            </header>

            <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3">
                <TextInput
                    type="text"
                    value={q}
                    onChange={(e) => onSearch(e.target.value)}
                    placeholder="Search title or company…"
                    aria-label="Search filtered candidates"
                    className="w-full lg:w-72"
                />
                <label className="text-fg-subtle flex items-center gap-2 text-xs">
                    Reason
                    <Select
                        value={filters.reason}
                        onChange={(e) => visit(q.trim(), e.target.value, 1)}
                        aria-label="Filter by reason"
                    >
                        <option value="all">All reasons</option>
                        {reasons.map((reason) => (
                            <option key={reason.code} value={reason.code}>
                                {reason.label}
                            </option>
                        ))}
                    </Select>
                </label>
                <p className="text-fg-subtle text-xs" role="status">
                    {pagination.total === 0
                        ? 'No candidates'
                        : `${pagination.from}–${pagination.to} of ${pagination.total}`}
                </p>
            </div>

            {rejections.length === 0 ? (
                <Card className="text-fg-muted mt-4 p-6 text-center text-sm">
                    {filtered
                        ? 'No filtered candidates match this search.'
                        : 'Nothing has been filtered out recently. Rejections appear here after a discovery run turns candidates away.'}
                </Card>
            ) : (
                <Card className="mt-4">
                    <ul className="divide-line divide-y">
                        {rejections.map((row) => (
                            <RejectionRow key={row.id} row={row} />
                        ))}
                    </ul>
                </Card>
            )}

            <Pagination
                page={pagination.page}
                lastPage={pagination.last_page}
                onPage={(page) => visit(filters.q, filters.reason, page)}
                className="mt-4"
            />
        </AppShell>
    );
}
