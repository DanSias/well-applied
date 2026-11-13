import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { ButtonLink } from '@/components/ui/button';
import { Card, SectionTitle } from '@/components/ui/surface';
import AppShell from '@/layouts/app-shell';
import { relativeTimeFromNow } from '@/lib/relative-time';
import { index as careerDataIndex } from '@/routes/career-data';
import {
    create as jobsCreate,
    index as jobsIndex,
    show as jobShow,
} from '@/routes/jobs';
import { show as workspaceShow } from '@/routes/jobs/profile-resumes';
import { index as resumeProfilesIndex } from '@/routes/resume-profiles';
import type {
    HomeAttentionItem,
    HomeContinueItem,
    HomeProps,
} from '@/types/home';

/** Where a row's action leads: the resume workspace when there is one, else the opportunity. */
function destination(
    jobId: number | null,
    variantId: number | null,
    filter: string | null = null,
): string {
    if (jobId === null) {
        return jobsIndex.url({ query: filter ? { triage: filter } : {} });
    }

    return variantId === null
        ? jobShow.url({ jobPosting: jobId })
        : workspaceShow.url({ jobPosting: jobId, resumeVariant: variantId });
}

function Stat({
    label,
    value,
    hint,
    href,
}: {
    label: string;
    value: number;
    hint?: string;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="bg-surface hover:bg-raised block px-4 py-3 transition-colors"
        >
            <p className="text-fg-subtle text-xs font-medium">{label}</p>
            <p className="text-fg mt-0.5 text-2xl leading-tight font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            <p className="text-fg-subtle mt-0.5 text-xs">{hint}</p>
        </Link>
    );
}

function AttentionRow({ item }: { item: HomeAttentionItem }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3.5">
            <div className="min-w-0 flex-1 basis-64">
                <p className="text-fg text-sm font-medium break-words">
                    {item.title}
                    {item.company && (
                        <span className="text-fg-muted font-normal">
                            {' '}
                            · {item.company}
                        </span>
                    )}
                </p>
                <p className="text-fg-muted mt-0.5 text-sm leading-relaxed break-words">
                    {item.message}
                </p>
            </div>
            <ButtonLink
                size="sm"
                href={destination(
                    item.job_id,
                    item.resume_variant_id,
                    item.filter,
                )}
            >
                {item.action}
            </ButtonLink>
        </li>
    );
}

function ContinueRow({ item }: { item: HomeContinueItem }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3.5">
            <div className="min-w-0 flex-1 basis-64">
                <p className="text-fg text-sm font-medium break-words">
                    {item.title}
                    <span className="text-fg-muted font-normal">
                        {' '}
                        · {item.company}
                    </span>
                </p>
                <p className="text-fg-subtle mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <Badge tone={item.status.tone}>{item.status.label}</Badge>
                    {item.activity_at && (
                        <span>{relativeTimeFromNow(item.activity_at)}</span>
                    )}
                </p>
            </div>
            <ButtonLink
                size="sm"
                href={destination(item.job_id, item.resume_variant_id)}
            >
                {item.action}
            </ButtonLink>
        </li>
    );
}

function QuickLink({
    href,
    title,
    description,
}: {
    href: string;
    title: string;
    description: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="rounded-card border-line bg-surface shadow-card hover:border-line-strong hover:bg-raised block border px-4 py-3.5 transition-colors"
        >
            <p className="text-fg text-sm font-medium">{title}</p>
            <p className="text-fg-subtle mt-0.5 text-xs leading-relaxed">
                {description}
            </p>
        </Link>
    );
}

export default function Home({
    has_profile,
    overview,
    attention,
    continue: recent,
    quick_access,
}: HomeProps) {
    // The single primary action: the most pressing item, else the most
    // recent work, else the first step.
    const top = attention.items[0] ?? null;
    const latest = recent[0] ?? null;
    // A resume destination reads "Continue resume"; the job it belongs to goes
    // in the tooltip and accessible name rather than the visible label.
    const primary = top
        ? {
              label:
                  top.resume_variant_id !== null
                      ? 'Continue resume'
                      : top.action,
              job: top.kind === 'summary' ? null : top.title,
              href: destination(top.job_id, top.resume_variant_id, top.filter),
          }
        : latest
          ? {
                label:
                    latest.resume_variant_id !== null
                        ? 'Continue resume'
                        : latest.action,
                job: latest.title,
                href: destination(latest.job_id, latest.resume_variant_id),
            }
          : { label: 'Add an opportunity', job: null, href: jobsCreate.url() };
    const empty = overview.opportunities === 0;

    return (
        <AppShell>
            <Head title="Overview" />

            <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                <div>
                    <h1 className="text-fg text-xl font-semibold tracking-tight sm:text-2xl">
                        Overview
                    </h1>
                    <p className="text-fg-muted mt-0.5 text-sm">
                        Where your job search stands and what to do next.
                    </p>
                </div>
                {has_profile && (
                    <div className="flex flex-wrap items-center gap-2">
                        {!empty && (
                            <ButtonLink href={jobsCreate.url()}>
                                Add opportunity
                            </ButtonLink>
                        )}
                        <ButtonLink
                            variant="primary"
                            href={primary.href}
                            title={primary.job ?? undefined}
                            aria-label={
                                primary.job
                                    ? `${primary.label}: ${primary.job}`
                                    : undefined
                            }
                        >
                            {primary.label}
                        </ButtonLink>
                    </div>
                )}
            </header>

            {!has_profile && (
                <Alert tone="warn" className="mt-5" role="status">
                    <p className="font-medium">No career data yet</p>
                    <p className="text-fg-muted mt-1">
                        Import your career dataset with{' '}
                        <code className="bg-sunken rounded px-1 py-0.5 text-xs">
                            php artisan career:import
                        </code>{' '}
                        to start tracking opportunities and building resumes.
                    </p>
                </Alert>
            )}

            {has_profile && empty && (
                <Card className="mt-5 p-6 text-center">
                    <h2 className="text-fg text-base font-semibold">
                        No opportunities yet
                    </h2>
                    <p className="text-fg-muted mx-auto mt-1.5 max-w-md text-sm leading-relaxed">
                        Add a job you are interested in. Well Applied analyzes
                        it, matches it against your approved evidence and helps
                        you tailor a resume.
                    </p>
                </Card>
            )}

            {has_profile && !empty && (
                <div className="mt-5 space-y-6">
                    <Card className="bg-line grid grid-cols-2 gap-px overflow-hidden lg:grid-cols-4">
                        <Stat
                            label="Opportunities"
                            value={overview.opportunities}
                            hint={
                                overview.not_analyzed > 0
                                    ? `${overview.not_analyzed} not analyzed`
                                    : 'All analyzed'
                            }
                            href={jobsIndex.url()}
                        />
                        <Stat
                            label="Processing now"
                            value={overview.in_flight}
                            hint="Analyzing or matching"
                            href={jobsIndex.url({
                                query: { triage: 'in-progress' },
                            })}
                        />
                        <Stat
                            label="Resumes in progress"
                            value={overview.resumes_in_progress}
                            hint="Not marked final"
                            href={jobsIndex.url()}
                        />
                        <Stat
                            label="Final resumes"
                            value={overview.resumes_final}
                            hint="Ready to apply with"
                            href={jobsIndex.url()}
                        />
                    </Card>

                    <section aria-label="Needs attention">
                        <SectionTitle
                            aside={
                                attention.total > attention.items.length
                                    ? `Showing ${attention.items.length} of ${attention.total}`
                                    : undefined
                            }
                        >
                            Needs attention
                        </SectionTitle>
                        {attention.items.length === 0 ? (
                            <Alert tone="ok" className="mt-3" role="status">
                                Nothing needs attention right now.
                            </Alert>
                        ) : (
                            <Card className="mt-3">
                                <ul className="divide-line divide-y">
                                    {attention.items.map((item) => (
                                        <AttentionRow
                                            key={item.key}
                                            item={item}
                                        />
                                    ))}
                                </ul>
                            </Card>
                        )}
                    </section>

                    <section aria-label="Continue working">
                        <SectionTitle>Continue working</SectionTitle>
                        {recent.length === 0 ? (
                            <Card className="text-fg-muted mt-3 p-5 text-sm">
                                No recent work yet. Analyze an opportunity to
                                get started.{' '}
                                <Link
                                    href={jobsIndex.url({
                                        query: { triage: 'raw' },
                                    })}
                                    className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
                                >
                                    Open opportunities
                                </Link>
                            </Card>
                        ) : (
                            <Card className="mt-3">
                                <ul className="divide-line divide-y">
                                    {recent.map((item) => (
                                        <ContinueRow
                                            key={item.job_id}
                                            item={item}
                                        />
                                    ))}
                                </ul>
                            </Card>
                        )}
                    </section>
                </div>
            )}

            {has_profile && (
                <section aria-label="Quick access" className="mt-6">
                    <SectionTitle>Quick access</SectionTitle>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <QuickLink
                            href={jobsIndex.url()}
                            title="Opportunities"
                            description="Triage, analyze and match job postings."
                        />
                        <QuickLink
                            href={resumeProfilesIndex.url()}
                            title="Resume Profiles"
                            description={
                                quick_access.resume_profiles === 0
                                    ? 'Study a kind of role and build evidence for it.'
                                    : `${quick_access.resume_profiles} ${quick_access.resume_profiles === 1 ? 'profile' : 'profiles'} you can tailor resumes from.`
                            }
                        />
                        <QuickLink
                            href={careerDataIndex.url()}
                            title="Career Data"
                            description="Browse the approved facts behind every resume."
                        />
                    </div>
                </section>
            )}
        </AppShell>
    );
}
