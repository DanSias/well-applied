import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { ButtonLink } from '@/components/ui/button';
import { Card } from '@/components/ui/surface';
import AppShell from '@/layouts/app-shell';
import { applicationRow, type ApplicationListItem } from '@/lib/submission';
import { index as opportunitiesIndex } from '@/routes/opportunities';

type Props = {
    applications: ApplicationListItem[];
    in_error: ApplicationListItem[];
    counts: { recorded: number; in_progress: number };
};

/** One recorded application: the job, when you applied, where it stands, and the resume you named. Read-only. */
function ApplicationRow({
    item,
    muted = false,
}: {
    item: ApplicationListItem;
    muted?: boolean;
}) {
    const row = applicationRow(item);

    return (
        <li className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1 px-4 py-2.5">
            <div className="min-w-0">
                <p className="text-sm leading-snug break-words">
                    <Link
                        href={row.href}
                        className={`focus-visible:ring-focus rounded-sm font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none ${muted ? 'text-fg-muted' : 'text-fg'}`}
                    >
                        {row.title}
                    </Link>
                    <span className="text-fg-muted"> · {row.company}</span>
                </p>
                <p className="text-fg-subtle mt-0.5 text-xs leading-relaxed break-words">
                    <span className="text-fg-muted">Applied {row.date}</span>
                    {' · '}
                    {row.resume}
                    {row.series && ` · ${row.series}`}
                    {row.note && ` · ${row.note}`}
                </p>
            </div>
            <div className="flex items-center justify-end gap-2">
                <Badge tone={muted ? 'neutral' : row.tone}>{row.status}</Badge>
            </div>
        </li>
    );
}

export default function ApplicationsIndex({
    applications,
    in_error,
    counts,
}: Props) {
    return (
        <AppShell>
            <Head title="Applications" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-fg text-xl font-semibold tracking-tight sm:text-2xl">
                        Applications
                    </h1>
                    <p className="text-fg-muted mt-1 text-sm">
                        {counts.recorded === 0
                            ? 'Applications you have submitted'
                            : `${counts.recorded} ${counts.recorded === 1 ? 'application' : 'applications'} you recorded${counts.in_progress > 0 ? `, ${counts.in_progress} still in progress` : ''}`}
                    </p>
                </div>
                <ButtonLink href={opportunitiesIndex.url()}>
                    Opportunities
                </ButtonLink>
            </div>

            {applications.length === 0 ? (
                <Card className="mt-5 border-dashed p-10 text-center">
                    <p className="text-fg-muted mx-auto max-w-md text-sm leading-relaxed">
                        Nothing recorded yet. When you apply to a job you are
                        pursuing, record it from its opportunity and it appears
                        here. Well Applied never submits for you.
                    </p>
                    <ButtonLink
                        variant="primary"
                        className="mt-4"
                        href={opportunitiesIndex.url()}
                    >
                        Go to Opportunities
                    </ButtonLink>
                </Card>
            ) : (
                <Card className="mt-3 overflow-hidden">
                    <ul className="divide-line divide-y">
                        {applications.map((item) => (
                            <ApplicationRow key={item.id} item={item} />
                        ))}
                    </ul>
                </Card>
            )}

            {in_error.length > 0 && (
                <details className="mt-5">
                    <summary className="text-fg-subtle cursor-pointer text-xs font-medium">
                        Recorded in error ({in_error.length})
                    </summary>
                    <Card className="mt-2 overflow-hidden">
                        <ul className="divide-line divide-y">
                            {in_error.map((item) => (
                                <ApplicationRow
                                    key={item.id}
                                    item={item}
                                    muted
                                />
                            ))}
                        </ul>
                    </Card>
                </details>
            )}
        </AppShell>
    );
}
