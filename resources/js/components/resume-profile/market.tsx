import { router } from '@inertiajs/react';
import { useState } from 'react';
import { build } from '@/routes/resume-profiles';
import {
    exclude as excludePosting,
    restore as restorePosting,
} from '@/routes/resume-profiles/postings';
import type {
    CoverageStatus,
    MarketGroup,
    ResumeProfilePage,
    SamplePosting,
} from '@/types/resume-profile';
import { buttonClass } from '@/components/ui/button';
import { fieldClass } from '@/components/ui/field';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/surface';
import { cn } from '@/lib/utils';

const secondary = buttonClass('secondary', 'sm');
const card =
    'rounded-md border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900';

export const CHIP: Record<CoverageStatus, string> = {
    directly_supported:
        'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    transferable:
        'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    underdocumented:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    limitation:
        'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    no_evidence: 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
};

export const SUMMARY_LABEL: Record<CoverageStatus, string> = {
    directly_supported: 'Documented',
    transferable: 'Related experience',
    underdocumented: 'Thinly documented',
    limitation: 'No direct experience',
    no_evidence: 'Not yet documented',
};

export function Percent({ value }: { value: number }) {
    return <>{Math.round(value * 100)}%</>;
}

export function CapabilityRow({
    capability,
}: {
    capability: MarketGroup['capabilities'][number];
}) {
    return (
        <li className="py-2">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm font-medium">{capability.label}</span>
                <span
                    className={`shrink-0 rounded px-2 py-0.5 text-xs ${CHIP[capability.coverage]}`}
                >
                    {capability.coverage_label}
                </span>
            </div>
            <div className="mt-1 flex items-center gap-3">
                <div
                    className="h-1.5 w-40 overflow-hidden rounded bg-neutral-200 dark:bg-neutral-800"
                    aria-hidden
                >
                    <div
                        className="h-full bg-neutral-700 dark:bg-neutral-300"
                        style={{
                            width: `${Math.round(capability.share * 100)}%`,
                        }}
                    />
                </div>
                <span className="text-xs text-neutral-500">
                    <Percent value={capability.share} /> of postings ·{' '}
                    <Percent value={capability.required_share} /> required ·{' '}
                    <Percent value={capability.preferred_share} /> preferred
                </span>
            </div>
            {capability.weak_postings > 0 && (
                <p className="mt-1 text-xs text-neutral-500">
                    Also hinted in {capability.weak_postings} more (only an
                    ambiguous word, not counted).
                </p>
            )}
            {capability.evidence.length > 0 && (
                <details className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                    <summary className="cursor-pointer">Evidence</summary>
                    <ul className="mt-1 space-y-1">
                        {capability.evidence.map((e, i) => (
                            <li key={i}>
                                <span className="font-medium">
                                    {e.company}, {e.title}
                                </span>{' '}
                                ({e.level}, {e.confidence} confidence
                                {e.matched ? `, matched "${e.matched}"` : ''}):
                                <span className="block italic">
                                    {e.snippet}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </li>
    );
}

export function MarketSection({ groups }: { groups: MarketGroup[] }) {
    const [open, setOpen] = useState<Record<string, boolean>>({});

    return (
        <div className="mt-3 grid gap-4 md:grid-cols-2">
            {groups.map((group) => {
                const shown = open[group.category]
                    ? group.capabilities
                    : group.capabilities.slice(0, 5);

                return (
                    <section key={group.category} className={card}>
                        <h3 className="text-sm font-semibold">{group.label}</h3>
                        <ul className="mt-1 divide-y divide-neutral-100 dark:divide-neutral-800">
                            {shown.map((capability) => (
                                <CapabilityRow
                                    key={capability.key}
                                    capability={capability}
                                />
                            ))}
                        </ul>
                        {group.capabilities.length > 5 && (
                            <button
                                type="button"
                                className="mt-2 text-xs underline underline-offset-2"
                                onClick={() =>
                                    setOpen((c) => ({
                                        ...c,
                                        [group.category]: !c[group.category],
                                    }))
                                }
                            >
                                {open[group.category]
                                    ? 'Show fewer'
                                    : `Show all ${group.capabilities.length}`}
                            </button>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

export function SampleSummary({ page }: { page: ResumeProfilePage }) {
    const sample = page.sample;

    if (!sample) {
        return null;
    }

    const when =
        sample.age_days === null
            ? ''
            : sample.age_days === 0
              ? 'today'
              : `${sample.age_days} day${sample.age_days === 1 ? '' : 's'} ago`;

    return (
        <div className="text-sm text-neutral-700 dark:text-neutral-300">
            <p>
                Based on <strong>{sample.size}</strong> real postings from{' '}
                {sample.companies} companies
                {sample.pilot ? ' (pilot sample)' : ''}, collected {when}
                {sample.posted_between[0] &&
                    `, posted ${sample.posted_between[0]} to ${sample.posted_between[1]}`}
                .
            </p>

            <details className="mt-2 text-xs text-neutral-600 dark:text-neutral-400">
                <summary className="cursor-pointer">
                    How the sample was chosen
                </summary>
                <p className="mt-2">
                    {sample.considered} postings were considered. Sources:{' '}
                    {sample.provider_log
                        .map(
                            (p) =>
                                `${p.provider} (${p.status.replaceAll('_', ' ')}, ${p.retrieved} new, ${p.reused} reused)`,
                        )
                        .join('; ')}
                    . Stored postings are reused; nothing sampled is added to
                    your opportunities.
                </p>
                {sample.exclusions.length > 0 && (
                    <ul className="mt-2 list-disc pl-5">
                        {sample.exclusions.map((e) => (
                            <li key={e.reason}>
                                {e.count} left out: {e.label}
                            </li>
                        ))}
                    </ul>
                )}
            </details>
        </div>
    );
}

export function PostingReviewCard({
    posting,
    slug,
}: {
    posting: SamplePosting;
    slug: string;
}) {
    const [reason, setReason] = useState('');
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const remove = () => {
        setBusy(true);
        router.post(
            excludePosting.url({ resumeProfile: slug, posting: posting.id }),
            { reason },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <li className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {posting.source_url ? (
                        <a
                            href={posting.source_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline underline-offset-2"
                        >
                            {posting.title}
                        </a>
                    ) : (
                        posting.title
                    )}
                    , {posting.company}
                </p>
                <span className="text-xs text-neutral-500">
                    {posting.provider}
                    {posting.posted_at ? ` · ${posting.posted_at}` : ''}
                    {posting.reused ? ' · already in your opportunities' : ''}
                </span>
            </div>
            <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                {posting.why_included}
            </p>
            <p className="mt-1 text-xs text-neutral-500">
                {posting.frontend.length > 0 &&
                    `Front-end: ${posting.frontend.join(', ')}. `}
                {posting.backend.length > 0 &&
                    `Back-end: ${posting.backend.join(', ')}. `}
                {posting.description.complete
                    ? `Full description (${posting.description.chars.toLocaleString()} characters).`
                    : 'Only a description preview.'}
            </p>
            {open ? (
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <input
                        className={cn(fieldClass, 'w-64 text-xs')}
                        placeholder="Why? (optional)"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <button
                        type="button"
                        className={secondary}
                        disabled={busy}
                        onClick={remove}
                    >
                        Remove from sample
                    </button>
                    <button
                        type="button"
                        className="text-xs underline"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </button>
                </div>
            ) : (
                <button
                    type="button"
                    className={`${secondary} mt-2`}
                    onClick={() => setOpen(true)}
                >
                    Not a good fit
                </button>
            )}
        </li>
    );
}

export function SampleReview({ page }: { page: ResumeProfilePage }) {
    const sample = page.sample;

    if (!sample) {
        return null;
    }

    return (
        <details className="mt-3 rounded-md border border-neutral-200 p-3 dark:border-neutral-800">
            <summary className="cursor-pointer text-sm font-medium">
                Review the sample ({sample.size} postings)
            </summary>
            <p className="mt-2 text-xs text-neutral-500">
                Removing a posting only takes it out of this study: the market
                picture and your questions are recalculated, and it stays out
                when the study is refreshed. Your opportunities are not changed.
                A small sample is fine to explore; treat it as a first
                impression.
            </p>
            <ul className="mt-3 space-y-2">
                {sample.postings.map((posting) => (
                    <PostingReviewCard
                        key={posting.id}
                        posting={posting}
                        slug={page.profile.slug}
                    />
                ))}
            </ul>
            {sample.user_excluded.length > 0 && (
                <div className="mt-4">
                    <p className="text-xs font-medium">Removed by you</p>
                    <ul className="mt-1 space-y-1 text-xs text-neutral-600 dark:text-neutral-400">
                        {sample.user_excluded.map((p) => (
                            <li key={p.id}>
                                {p.title}, {p.company} ({p.provider}).{' '}
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() =>
                                        router.post(
                                            restorePosting.url({
                                                resumeProfile:
                                                    page.profile.slug,
                                                posting: p.id,
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Put it back
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </details>
    );
}

export function MarketTab({ page }: { page: ResumeProfilePage }) {
    const [busy, setBusy] = useState(false);
    const building = page.build.state === 'building';
    const counts = page.coverage.counts;
    const start = (pilot: boolean) => {
        setBusy(true);
        router.post(
            build.url(page.profile.slug),
            { pilot },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="space-y-6">
            <Card className="p-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="max-w-prose">
                        <h2 className="text-fg text-base font-semibold tracking-tight">
                            What the market asks for
                        </h2>
                        <p className="text-fg-muted mt-1 text-sm leading-relaxed">
                            Market research informs how your profile is
                            positioned. You do not need to repeat it each time
                            you open the profile; refresh it when you want a
                            newer sample.
                        </p>
                    </div>
                    <div className="flex flex-col items-end gap-1.5">
                        <Button
                            variant={page.sample ? 'secondary' : 'primary'}
                            disabled={busy || building}
                            onClick={() => start(false)}
                        >
                            {building
                                ? 'Studying the market…'
                                : page.build.label}
                        </Button>
                        {page.build.state === 'none' && (
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={busy}
                                onClick={() => start(true)}
                            >
                                Start with a smaller pilot sample
                            </Button>
                        )}
                    </div>
                </div>

                {page.build.state === 'none' && (
                    <ol className="text-fg-muted mt-4 list-decimal space-y-1 pl-5 text-sm leading-relaxed">
                        <li>
                            Career Toolkit reads a sample of current full-stack
                            job postings and notes what employers keep asking
                            for.
                        </li>
                        <li>
                            It compares that with your career data and shows
                            what is already documented.
                        </li>
                        <li>
                            It asks short questions about what the market wants
                            and your record does not yet say. You review every
                            answer before it is saved.
                        </li>
                    </ol>
                )}
                {page.build.state === 'none' && (
                    <p className="text-fg-subtle mt-3 text-xs">
                        Nothing is sent to an AI model, and sampled postings are
                        not added to your opportunities.
                    </p>
                )}
                {page.build.state === 'failed' && (
                    <Alert tone="bad" role="alert" className="mt-4">
                        The last market study did not finish. You can try again.
                    </Alert>
                )}
                {building && (
                    <p className="text-fg-muted mt-4 text-sm">
                        Reading current postings and comparing them with your
                        record. This page updates by itself.
                    </p>
                )}
            </Card>

            {page.sample && (
                <>
                    <Card className="p-5">
                        <SampleSummary page={page} />
                        <div className="mt-3">
                            <SampleReview page={page} />
                        </div>
                    </Card>

                    <section aria-label="How your record compares">
                        <h3 className="text-fg text-sm font-semibold">
                            How your record compares
                        </h3>
                        <div className="mt-2 flex flex-wrap gap-2 text-xs">
                            {(
                                Object.keys(SUMMARY_LABEL) as CoverageStatus[]
                            ).map((status) => (
                                <span
                                    key={status}
                                    className={`rounded px-2 py-1 ${CHIP[status]}`}
                                >
                                    {SUMMARY_LABEL[status]}:{' '}
                                    {counts[status] ?? 0}
                                </span>
                            ))}
                        </div>
                        <p className="text-fg-subtle mt-2 text-xs">
                            This counts only evidence you have approved and that
                            is allowed on a resume. Private notes are never
                            used.
                        </p>
                    </section>

                    <MarketSection groups={page.market} />
                </>
            )}
        </div>
    );
}
