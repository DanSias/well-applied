import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { GenerationStatus } from '@/components/generation-status';
import { store as startReview } from '@/routes/jobs/profile-resumes/readiness';
import type {
    ReadinessRequirement,
    ReadinessResult,
    ReadinessState,
    ReadinessTarget,
} from '@/types/resume-variant';

const BUTTON =
    'rounded-md border border-neutral-300 px-2 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-40 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800';
const PRIMARY =
    'inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300';

const PHASE: Record<string, string> = {
    document: 'Reading the PDF',
    assessment: 'Assessing coverage',
    classification: 'Checking your approved evidence',
};

const GAP_LABEL: Record<string, string> = {
    in_resume: 'On the resume',
    omitted_available: 'In your record, not on the resume',
    already_shown: 'All your approved evidence is already shown',
    unmapped: 'Judged from the resume text only',
    transferable: 'Related experience only',
    not_established: 'Not established by your record',
};

const DEPTH_LABEL: Record<string, string> = {
    built: 'Built it',
    supported: 'Supported it',
    adjacent: 'Related work',
    none: '',
};

function Chip({
    children,
    tone = 'neutral',
}: {
    children: React.ReactNode;
    tone?: 'neutral' | 'green' | 'amber' | 'red';
}) {
    const tones = {
        neutral:
            'border-neutral-300 bg-neutral-100 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
        green: 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300',
        amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
        red: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
    };

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${tones[tone]}`}
        >
            {children}
        </span>
    );
}

function Requirement({
    r,
    result,
    onOpen,
}: {
    r: ReadinessRequirement;
    result: ReadinessResult;
    onOpen: (target: ReadinessTarget) => void;
}) {
    const gap = r.gap;

    return (
        <li className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-neutral-900 dark:text-neutral-100">
                    {r.text}
                </span>
                <Chip tone={r.priority === 'critical' ? 'amber' : 'neutral'}>
                    {r.priority}
                </Chip>
                {DEPTH_LABEL[r.depth] && <Chip>{DEPTH_LABEL[r.depth]}</Chip>}
                {gap && gap.status !== 'in_resume' && (
                    <Chip
                        tone={
                            gap.status === 'omitted_available'
                                ? 'green'
                                : 'amber'
                        }
                    >
                        {GAP_LABEL[gap.status]}
                    </Chip>
                )}
            </div>
            {r.note && (
                <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                    {r.note}
                </p>
            )}
            {r.passage_ids.length > 0 && (
                <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                    Shown in:{' '}
                    {r.passage_ids.map((p, i) => {
                        const passage = result.passages[p];

                        return (
                            <span key={p}>
                                {i > 0 && '; '}
                                {passage?.bullet_id ? (
                                    <button
                                        type="button"
                                        className="underline underline-offset-2"
                                        onClick={() =>
                                            onOpen({
                                                type: 'bullet',
                                                bullet_id:
                                                    passage.bullet_id as number,
                                            })
                                        }
                                    >
                                        {passage.label}
                                    </button>
                                ) : (
                                    (passage?.label ?? p)
                                )}
                            </span>
                        );
                    })}
                </p>
            )}
            {gap && gap.status !== 'in_resume' && (
                <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {gap.reason}
                </p>
            )}
            {gap?.candidates.map((c) => (
                <div
                    key={c.key}
                    className="mt-2 flex items-start justify-between gap-3 rounded-md bg-green-50 p-2 text-xs text-green-900 dark:bg-green-950 dark:text-green-200"
                >
                    <span>{c.statement}</span>
                    {c.role_id !== undefined && (
                        <button
                            type="button"
                            className={BUTTON}
                            onClick={() =>
                                onOpen({
                                    type: 'role_fact',
                                    role_id: c.role_id as number,
                                    fact_key: c.key,
                                })
                            }
                        >
                            Use in the editor
                        </button>
                    )}
                </div>
            ))}
            {gap && gap.adjacent.length > 0 && (
                <details className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                    <summary className="cursor-pointer">
                        Related evidence (not the same thing; do not present it
                        as this)
                    </summary>
                    <ul className="list-disc pl-5">
                        {gap.adjacent.map((a) => (
                            <li key={a.key}>{a.statement}</li>
                        ))}
                    </ul>
                </details>
            )}
        </li>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="mt-6">
            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                {title}
            </h3>
            {children}
        </section>
    );
}

export function ReadinessReview({
    jobId,
    variantId,
    readiness,
    onOpen,
}: {
    jobId: number;
    variantId: number;
    readiness: ReadinessState;
    onOpen: (target: ReadinessTarget) => void;
}) {
    const [starting, setStarting] = useState(false);
    const active =
        readiness.state === 'queued' || readiness.state === 'running';

    // Follow a running review; the review row is the truth, so a reload mid-run shows the right elapsed time.
    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['readiness'] }),
            3000,
        );

        return () => clearInterval(timer);
    }, [active]);

    const start = () => {
        setStarting(true);
        router.post(
            startReview.url({ jobPosting: jobId, resumeVariant: variantId }),
            {},
            {
                preserveScroll: true,
                only: ['readiness'],
                onFinish: () => setStarting(false),
            },
        );
    };

    if (active) {
        return (
            <div className="mt-4">
                <p className="text-sm text-neutral-700 dark:text-neutral-300">
                    Reviewing this version against the job. This uses one local
                    model call and can take a minute or two.
                </p>
                <GenerationStatus
                    active
                    label={PHASE[readiness.phase ?? ''] ?? 'Reviewing'}
                    queued={readiness.state === 'queued'}
                    queuedLabel="Waiting for the worker…"
                    startedAt={readiness.started_at ?? null}
                />
            </div>
        );
    }

    if (readiness.state === 'none') {
        return (
            <div className="mt-4 rounded-lg border border-neutral-200 p-4 dark:border-neutral-800">
                <p className="text-sm text-neutral-700 dark:text-neutral-300">
                    Check this exact version against the job before you submit
                    it: which requirements the resume clearly shows, what it
                    leaves out, what your record can and cannot support, and
                    whether the PDF reads cleanly. It never rewrites anything.
                </p>
                <button
                    type="button"
                    className={`${PRIMARY} mt-3`}
                    onClick={start}
                    disabled={starting}
                >
                    Run readiness review
                </button>
            </div>
        );
    }

    if (readiness.state === 'failed') {
        return (
            <div className="mt-4">
                <p
                    role="alert"
                    className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
                >
                    The review did not finish:{' '}
                    {readiness.message ?? 'unknown error'}
                </p>
                <button
                    type="button"
                    className={`${PRIMARY} mt-3`}
                    onClick={start}
                    disabled={starting}
                >
                    Try again
                </button>
            </div>
        );
    }

    if (readiness.state === 'stale') {
        return (
            <div className="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                <p className="font-medium">The last review is out of date.</p>
                <ul className="mt-1 list-disc pl-5">
                    {(readiness.stale_reasons ?? []).map((reason) => (
                        <li key={reason}>{reason}</li>
                    ))}
                </ul>
                <button
                    type="button"
                    className={`${PRIMARY} mt-3`}
                    onClick={start}
                    disabled={starting}
                >
                    Run a new review
                </button>
            </div>
        );
    }

    const result = readiness.result!;
    const groups: [string, ReadinessRequirement[]][] = [
        ['Strong', result.requirements.filter((r) => r.coverage === 'strong')],
        [
            'Partial',
            result.requirements.filter((r) => r.coverage === 'partial'),
        ],
        [
            'Missing',
            result.requirements.filter((r) => r.coverage === 'missing'),
        ],
    ];
    const defects = result.document.findings.filter((f) => f.kind === 'defect');
    const suggestions = result.document.findings.filter(
        (f) => f.kind === 'suggestion',
    );
    const rating = result.hiring_argument.rating;
    const metrics = readiness.metrics ?? {};

    return (
        <div className="mt-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    Review of this exact version, finished{' '}
                    {readiness.reviewed_at
                        ? new Date(readiness.reviewed_at).toLocaleString()
                        : ''}
                    . Edits you make later need a new review.
                </p>
                <button
                    type="button"
                    className={BUTTON}
                    onClick={start}
                    disabled={starting}
                >
                    Run again
                </button>
            </div>

            <div className="mt-3 rounded-lg border border-neutral-200 p-4 dark:border-neutral-800">
                <div className="flex flex-wrap items-center gap-2">
                    <Chip
                        tone={
                            rating === 'persuasive'
                                ? 'green'
                                : rating === 'weak'
                                  ? 'red'
                                  : 'amber'
                        }
                    >
                        {rating === 'persuasive'
                            ? 'Persuasive'
                            : rating === 'weak'
                              ? 'Weak case'
                              : 'Adequate case'}
                    </Chip>
                    <Chip tone="green">{result.counts.strong} strong</Chip>
                    <Chip tone="amber">{result.counts.partial} partial</Chip>
                    <Chip tone={result.counts.missing > 0 ? 'red' : 'neutral'}>
                        {result.counts.missing} missing
                    </Chip>
                </div>
                <p className="mt-2 text-sm text-neutral-800 dark:text-neutral-200">
                    {result.hiring_argument.summary}
                </p>
                {(result.emphasis.over.length > 0 ||
                    result.emphasis.under.length > 0) && (
                    <p className="mt-2 text-xs text-neutral-600 dark:text-neutral-400">
                        {result.emphasis.over.length > 0 && (
                            <>
                                Too much room: {result.emphasis.over.join('; ')}
                                .{' '}
                            </>
                        )}
                        {result.emphasis.under.length > 0 && (
                            <>Too little: {result.emphasis.under.join('; ')}.</>
                        )}
                    </p>
                )}
            </div>

            <Section title="Best improvements">
                <ol className="mt-2 space-y-3">
                    {result.improvements.map((imp, i) => (
                        <li
                            key={i}
                            className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800"
                        >
                            <p className="font-medium text-neutral-900 dark:text-neutral-100">
                                {i + 1}. {imp.title}
                            </p>
                            <p className="text-neutral-700 dark:text-neutral-300">
                                {imp.advice}
                            </p>
                            {imp.facts.map((f) => (
                                <p
                                    key={f.key}
                                    className="mt-1 rounded bg-green-50 p-2 text-xs text-green-900 dark:bg-green-950 dark:text-green-200"
                                >
                                    {f.statement}
                                </p>
                            ))}
                            {imp.target && (
                                <button
                                    type="button"
                                    className={`${BUTTON} mt-2`}
                                    onClick={() => onOpen(imp.target)}
                                >
                                    {imp.target.type === 'role_fact'
                                        ? 'Use in the editor'
                                        : 'Open in the editor'}
                                </button>
                            )}
                        </li>
                    ))}
                </ol>
            </Section>

            <Section title="Requirements">
                {groups.map(
                    ([name, list]) =>
                        list.length > 0 && (
                            <div key={name} className="mt-3">
                                <p className="text-xs font-semibold tracking-wide text-neutral-500 uppercase dark:text-neutral-400">
                                    {name}
                                </p>
                                <ul className="mt-1 space-y-2">
                                    {list.map((r) => (
                                        <Requirement
                                            key={r.id}
                                            r={r}
                                            result={result}
                                            onOpen={onOpen}
                                        />
                                    ))}
                                </ul>
                            </div>
                        ),
                )}
            </Section>

            {result.gaps.length > 0 && (
                <Section title="Qualification gaps">
                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        The job asks for these and your approved record does not
                        establish them. They are not wording to add.
                    </p>
                    <ul className="mt-2 list-disc pl-5 text-sm text-neutral-800 dark:text-neutral-200">
                        {result.gaps.map((g) => (
                            <li key={g.requirement_id}>
                                {g.text}{' '}
                                <span className="text-xs text-neutral-500">
                                    ({GAP_LABEL[g.status]})
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section title="Document and ATS checks">
                {defects.length === 0 ? (
                    <p className="mt-2 text-sm text-green-800 dark:text-green-300">
                        No document defects found in the {result.document.pages}
                        -page PDF.
                    </p>
                ) : (
                    <ul className="mt-2 space-y-1 text-sm text-red-700 dark:text-red-300">
                        {defects.map((f) => (
                            <li key={f.code}>Defect: {f.message}</li>
                        ))}
                    </ul>
                )}
                {suggestions.length > 0 && (
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-neutral-700 dark:text-neutral-300">
                        {suggestions.map((f) => (
                            <li key={f.code}>{f.message}</li>
                        ))}
                    </ul>
                )}
                {result.terms.filter(
                    (t) => t.status === 'supported_not_in_resume',
                ).length > 0 && (
                    <p className="mt-2 text-xs text-neutral-600 dark:text-neutral-400">
                        Job terms your record supports that the resume never
                        uses:{' '}
                        {result.terms
                            .filter(
                                (t) => t.status === 'supported_not_in_resume',
                            )
                            .map((t) => t.capability)
                            .join(', ')}
                        .
                    </p>
                )}
                <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                    These describe the document, not how any applicant tracking
                    system will score it.
                </p>
            </Section>

            {(result.risks.composition.length > 0 ||
                result.risks.wording.length > 0) && (
                <Section title="Claim and credibility risks">
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-neutral-800 dark:text-neutral-200">
                        {result.risks.composition.map((c, i) => (
                            <li key={`c${i}`}>{c.message}</li>
                        ))}
                        {result.risks.wording.map((w) => (
                            <li key={w.label}>
                                {w.label}: {w.messages.join(' ') || w.status}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <details className="mt-6 rounded-lg border border-neutral-200 p-4 text-sm dark:border-neutral-800">
                <summary className="cursor-pointer font-semibold text-neutral-900 dark:text-neutral-100">
                    Review details
                </summary>
                <dl className="mt-3 grid gap-x-6 gap-y-1 text-xs text-neutral-500 sm:grid-cols-2 dark:text-neutral-400">
                    <div>Model: {readiness.model}</div>
                    <div>Method: {result.prompt_version}</div>
                    <div>
                        Total: {Math.round((metrics.duration_ms ?? 0) / 1000)} s
                        (model {Math.round((metrics.model_ms ?? 0) / 1000)} s)
                    </div>
                    <div>
                        Tokens: {metrics.prompt_tokens ?? '?'} in,{' '}
                        {metrics.completion_tokens ?? '?'} out
                        {metrics.reasoning_effort &&
                            `, reasoning ${metrics.reasoning_effort}`}
                    </div>
                    <div className="break-all sm:col-span-2">
                        Document: {readiness.fingerprints?.artifact}
                    </div>
                    <div className="break-all sm:col-span-2">
                        Job description: {readiness.fingerprints?.description}
                    </div>
                </dl>
                {result.risks.adjustments.length > 0 && (
                    <div className="mt-3">
                        <p className="font-medium text-neutral-800 dark:text-neutral-200">
                            Where the review was corrected against the resume
                        </p>
                        <ul className="mt-1 list-disc pl-5 text-xs text-neutral-600 dark:text-neutral-400">
                            {result.risks.adjustments.map((a) => (
                                <li key={a}>{a}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </details>
        </div>
    );
}
