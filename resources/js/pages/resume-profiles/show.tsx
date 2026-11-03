import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ApprovedEvidencePanel } from '@/components/approved-evidence-panel';
import { QuestionCard } from '@/components/clarification-panel';
import { ClaimExpansion } from '@/components/claim-expansion';
import { ConsolidationReview } from '@/components/consolidation-review';
import type { QuestionRoutes } from '@/components/clarification-panel';
import AppShell from '@/layouts/app-shell';
import {
    answer,
    build,
    improveQuestions,
    index,
    reopen,
    skip,
} from '@/routes/resume-profiles';
import {
    exclude as excludePosting,
    restore as restorePosting,
} from '@/routes/resume-profiles/postings';
import type {
    CoverageStatus,
    MarketGroup,
    InterviewAnswer,
    ResumeProfilePage,
    SamplePosting,
} from '@/types/resume-profile';

const primary =
    'rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900';
const secondary =
    'rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300';
const card =
    'rounded-md border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900';

const CHIP: Record<CoverageStatus, string> = {
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

const SUMMARY_LABEL: Record<CoverageStatus, string> = {
    directly_supported: 'Documented',
    transferable: 'Related experience',
    underdocumented: 'Thinly documented',
    limitation: 'No direct experience',
    no_evidence: 'Not yet documented',
};

function Percent({ value }: { value: number }) {
    return <>{Math.round(value * 100)}%</>;
}

function CapabilityRow({
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

function MarketSection({ groups }: { groups: MarketGroup[] }) {
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

function SampleSummary({ page }: { page: ResumeProfilePage }) {
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

function PostingReviewCard({
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
                        className="w-64 rounded-md border border-neutral-300 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-950"
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

function SampleReview({ page }: { page: ResumeProfilePage }) {
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

function WordingPanel({
    slug,
    state,
    original,
    onOriginal,
}: {
    slug: string;
    state: ResumeProfilePage['interview']['enrichment'];
    original: boolean;
    onOriginal: (value: boolean) => void;
}) {
    const [busy, setBusy] = useState(false);
    const running = state.running;

    const improve = () => {
        setBusy(true);
        router.post(
            improveQuestions.url(slug),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="mt-3 flex flex-wrap items-center gap-3 rounded-md bg-neutral-50 p-3 text-xs text-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">
            <span>
                {state.current > 0
                    ? `${state.current} of ${state.open} open questions use improved wording${state.model ? ` (${state.model}${state.last_at ? `, ${state.last_at.slice(0, 10)}` : ''})` : ''}.`
                    : 'Questions use the standard wording.'}
                {state.pending > 0 &&
                    state.current > 0 &&
                    ` ${state.pending} changed since and use the standard wording.`}
            </span>
            <button
                type="button"
                className={secondary}
                disabled={
                    busy || running || state.pending === 0 || !state.available
                }
                onClick={improve}
                title={
                    state.available
                        ? 'Rewrites the open questions with your local model, once. Nothing runs automatically.'
                        : 'No local model is configured (OLLAMA_BASE_URL).'
                }
            >
                {running
                    ? 'Improving questions...'
                    : state.pending === 0 && state.current > 0
                      ? 'Questions improved'
                      : 'Improve interview questions'}
            </button>
            {state.current > 0 && (
                <label className="flex items-center gap-1.5">
                    <input
                        type="checkbox"
                        checked={original}
                        onChange={(e) => onOriginal(e.target.checked)}
                    />
                    Show the standard wording
                </label>
            )}
        </div>
    );
}

function AnswerReview({
    item,
    page,
}: {
    item: InterviewAnswer;
    page: ResumeProfilePage;
}) {
    const p = item.proposal;
    const status = !p
        ? 'No proposal'
        : p.status === 'approved'
          ? p.kind === 'fact'
              ? `Approved as a career fact (${p.fact_key})`
              : p.kind === 'limitation'
                ? 'Approved as a known limitation'
                : 'Approved as presentation guidance'
          : p.status === 'rejected'
            ? 'Rejected, nothing was saved'
            : 'Waiting for your approval';

    return (
        <li className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">{item.topic}</p>
                <span
                    className={`rounded px-2 py-0.5 text-xs ${
                        p?.status === 'approved'
                            ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200'
                            : p?.status === 'proposed'
                              ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'
                              : 'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'
                    }`}
                >
                    {status}
                </span>
            </div>

            <p className="mt-2 text-xs font-medium text-neutral-500">
                Your original answer
            </p>
            <p className="mt-0.5 whitespace-pre-wrap">
                &ldquo;{item.answer.text}&rdquo;
            </p>
            {item.answer.scopes.length > 0 && (
                <p className="mt-1 text-xs text-neutral-500">
                    You said: {item.answer.scopes.join('; ')}
                </p>
            )}

            {p && p.kind === 'fact' && (
                <dl className="mt-2 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[8rem_1fr]">
                    <dt className="text-neutral-500">Proposed fact</dt>
                    <dd>{p.statement}</dd>
                    <dt className="text-neutral-500">Scope</dt>
                    <dd>{p.scopes.join('; ') || 'None selected'}</dd>
                    <dt className="text-neutral-500">Attributed to</dt>
                    <dd>{p.attribution ?? 'Not chosen yet'}</dd>
                    <dt className="text-neutral-500">Visibility</dt>
                    <dd>{p.visibility}</dd>
                    <dt className="text-neutral-500">Verification</dt>
                    <dd>{p.verification.replaceAll('_', ' ')}</dd>
                </dl>
            )}

            {item.separate_claims.likely && (
                <div className="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <p className="font-medium">
                        This answer may contain more than one claim.
                    </p>
                    <ul className="mt-1 list-disc pl-4">
                        {item.separate_claims.reasons.map((r) => (
                            <li key={r}>{r}</li>
                        ))}
                    </ul>
                    <p className="mt-1">
                        Each fact has one scope, one employer or project and one
                        visibility. If these are separate accomplishments, edit
                        the proposed statement to cover just one before
                        approving. Your full answer stays on record.
                    </p>
                </div>
            )}

            <ClaimExpansion
                answerId={item.answer_id}
                expansion={item.expansion}
                options={page.interview.attribution_options}
                scopes={page.interview.scopes}
            />

            <details className="mt-2 text-xs text-neutral-500">
                <summary className="cursor-pointer">Question asked</summary>
                <p className="mt-1">{item.asked}</p>
            </details>
        </li>
    );
}

function AnswersReview({ page }: { page: ResumeProfilePage }) {
    const answers = page.interview.answers;

    if (answers.length === 0) {
        return null;
    }

    const waiting = answers.filter(
        (a) => a.proposal?.status === 'proposed',
    ).length;

    return (
        <section className="mt-8">
            <h2 className="text-base font-semibold">Your answers so far</h2>
            <p className="mt-1 text-xs text-neutral-500">
                {answers.length} answered, {waiting} waiting for your approval.
                Nothing becomes a career fact until you approve it.
            </p>
            <ul className="mt-3 space-y-3">
                {answers.map((item) => (
                    <AnswerReview
                        key={item.question_id}
                        item={item}
                        page={page}
                    />
                ))}
            </ul>
        </section>
    );
}

function Interview({ page }: { page: ResumeProfilePage }) {
    const { interview } = page;
    const slug = page.profile.slug;
    const [original, setOriginal] = useState(false);
    const [group, setGroup] = useState<number | null>(
        interview.progress.current_group ??
            (interview.progress.groups.length > 0 ? 1 : null),
    );

    const routes: QuestionRoutes = {
        answer: (id) => answer.url({ resumeProfile: slug, question: id }),
        skip: (id) => skip.url({ resumeProfile: slug, question: id }),
        reopen: (id) => reopen.url({ resumeProfile: slug, question: id }),
    };

    // Questions carry their own copy of the shared clarification shape the cards expect.
    const clarification = {
        session_id: interview.session_id ?? 0,
        mode: 'deep' as const,
        can_deepen: false,
        resume_ready: false,
        questions: interview.questions,
        attribution_options: interview.attribution_options,
        scopes: interview.scopes,
        limitations: interview.limitations,
        preferences: interview.preferences,
    };

    const { progress } = interview;
    const resolved = progress.total - progress.open;

    if (progress.total === 0) {
        return (
            <p className="mt-2 text-sm text-neutral-500">
                No questions yet. Nothing in the market sample is missing from
                your record, or the market study has not run.
            </p>
        );
    }

    return (
        <div className="mt-2">
            <p className="text-sm text-neutral-700 dark:text-neutral-300">
                {resolved} of {progress.total} topics handled
                {progress.skipped > 0 && ` (${progress.skipped} skipped)`}
                {progress.covered > 0 &&
                    `, ${progress.covered} already covered by your record`}
                . You can stop at any point and pick up here later.
            </p>

            <WordingPanel
                slug={slug}
                state={interview.enrichment}
                original={original}
                onOriginal={setOriginal}
            />

            <div className="mt-3 flex flex-wrap gap-2">
                {progress.groups.map((g) => (
                    <button
                        key={g.index}
                        type="button"
                        onClick={() => setGroup(g.index)}
                        className={`${secondary} ${group === g.index ? 'border-neutral-900 bg-neutral-100 dark:border-neutral-100 dark:bg-neutral-800' : ''}`}
                    >
                        Group {g.index}: {g.done}/{g.total}
                    </button>
                ))}
            </div>

            <ol className="mt-3 space-y-3">
                {interview.questions
                    .filter((q) => q.group === group)
                    .map((q) => (
                        <QuestionCard
                            key={q.id}
                            routes={routes}
                            question={
                                original ? { ...q, text: q.original_text } : q
                            }
                            clarification={clarification}
                        />
                    ))}
            </ol>
        </div>
    );
}

export default function ResumeProfileShow({
    resume_profile: page,
}: {
    resume_profile: ResumeProfilePage;
}) {
    const [busy, setBusy] = useState(false);
    const building = page.build.state === 'building';
    const polling =
        building ||
        page.interview.enrichment.running ||
        page.interview.answers.some((a) => a.expansion.state === 'running');

    // While the study runs on the queue, refresh quietly until it finishes.
    useEffect(() => {
        if (!polling) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['resume_profile'] }),
            4000,
        );

        return () => window.clearInterval(timer);
    }, [polling]);

    const start = (pilot: boolean) => {
        setBusy(true);
        router.post(
            build.url(page.profile.slug),
            { pilot },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const counts = page.coverage.counts;

    return (
        <AppShell>
            <Head title={page.profile.name} />
            <Link
                href={index.url()}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400"
            >
                ← Resume Profiles
            </Link>

            <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                <div className="max-w-2xl">
                    <h1 className="text-xl font-semibold">
                        {page.profile.name}
                    </h1>
                    <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {page.profile.purpose}
                    </p>
                    <p className="mt-2 text-xs text-neutral-500">
                        {page.profile.status_label}
                    </p>
                </div>
                <div className="flex flex-col items-end gap-1">
                    <button
                        type="button"
                        className={primary}
                        disabled={busy || building}
                        onClick={() => start(false)}
                    >
                        {building ? 'Studying the market...' : page.build.label}
                    </button>
                    {page.build.state === 'none' && (
                        <button
                            type="button"
                            className="text-xs underline underline-offset-2"
                            disabled={busy}
                            onClick={() => start(true)}
                        >
                            Start with a smaller pilot sample
                        </button>
                    )}
                </div>
            </div>

            {page.build.state === 'none' && (
                <div className={`${card} mt-6 text-sm`}>
                    <p className="font-medium">How this works</p>
                    <ol className="mt-2 list-decimal space-y-1 pl-5 text-neutral-700 dark:text-neutral-300">
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
                    <p className="mt-2 text-xs text-neutral-500">
                        Nothing is sent to an AI model, and sampled postings are
                        not added to your opportunities.
                    </p>
                </div>
            )}

            {page.build.state === 'failed' && (
                <p className="mt-6 text-sm text-red-600">
                    The last market study did not finish. You can try again.
                </p>
            )}

            {building && (
                <p className="mt-6 text-sm text-neutral-600 dark:text-neutral-400">
                    Reading current postings and comparing them with your
                    record. This page updates by itself.
                </p>
            )}

            {page.sample && (
                <>
                    <section className="mt-8">
                        <h2 className="text-base font-semibold">
                            What the market asks for
                        </h2>
                        <div className="mt-2">
                            <SampleSummary page={page} />
                            <SampleReview page={page} />
                        </div>
                        <MarketSection groups={page.market} />
                    </section>

                    <section className="mt-8">
                        <h2 className="text-base font-semibold">
                            How your record compares
                        </h2>
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
                        <p className="mt-2 text-xs text-neutral-500">
                            This counts only evidence you have approved and that
                            is allowed on a resume. Private notes are never
                            used.
                        </p>
                    </section>
                </>
            )}

            {page.sample && (
                <section className="mt-8">
                    <h2 className="text-base font-semibold">
                        Your discovery interview
                    </h2>
                    <Interview page={page} />
                </section>
            )}

            <ConsolidationReview queue={page.consolidation} />
            <AnswersReview page={page} />

            {page.contributed.facts.length > 0 && (
                <div className="mt-8">
                    <ApprovedEvidencePanel evidence={page.contributed} />
                </div>
            )}
        </AppShell>
    );
}
