import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { GenerationStatus } from '@/components/generation-status';
import { Alert } from '@/components/ui/alert';
import type { Tone } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, Disclosure, SectionTitle } from '@/components/ui/surface';
import { cn } from '@/lib/utils';
import { store as startReview } from '@/routes/jobs/profile-resumes/readiness';
import type {
    ReadinessRequirement,
    ReadinessResult,
    ReadinessState,
    ReadinessTarget,
} from '@/types/resume-variant';

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

const COVERAGE_TONE: Record<ReadinessRequirement['coverage'], Tone> = {
    strong: 'ok',
    partial: 'warn',
    missing: 'bad',
};

const DOT: Record<Tone, string> = {
    neutral: 'bg-fg-subtle',
    accent: 'bg-accent',
    ok: 'bg-ok',
    warn: 'bg-warn',
    bad: 'bg-bad',
};

function Dot({ tone }: { tone: Tone }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'mt-[0.45rem] inline-block h-2 w-2 shrink-0 rounded-full',
                DOT[tone],
            )}
        />
    );
}

/** A suggested piece of the user's own approved evidence. */
function EvidenceQuote({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-control border-ok bg-sunken text-fg-muted border-l-2 px-3 py-2 text-sm leading-relaxed break-words">
            {children}
        </p>
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
        <li className="px-3 py-2.5">
            <Disclosure
                summary={
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="text-fg text-sm font-medium break-words">
                            {r.text}
                        </span>
                        <Badge
                            tone={
                                r.priority === 'critical' ? 'warn' : 'neutral'
                            }
                        >
                            {r.priority}
                        </Badge>
                    </span>
                }
            >
                <div className="space-y-2 text-sm">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {DEPTH_LABEL[r.depth] && (
                            <Badge>{DEPTH_LABEL[r.depth]}</Badge>
                        )}
                        {gap && gap.status !== 'in_resume' && (
                            <Badge
                                tone={
                                    gap.status === 'omitted_available'
                                        ? 'ok'
                                        : 'warn'
                                }
                            >
                                {GAP_LABEL[gap.status]}
                            </Badge>
                        )}
                    </div>
                    {r.note && (
                        <p className="text-fg-muted leading-relaxed break-words">
                            {r.note}
                        </p>
                    )}
                    {r.passage_ids.length > 0 && (
                        <p className="text-fg-subtle text-xs leading-relaxed">
                            Shown in:{' '}
                            {r.passage_ids.map((p, i) => {
                                const passage = result.passages[p];

                                return (
                                    <span key={p}>
                                        {i > 0 && '; '}
                                        {passage?.bullet_id ? (
                                            <button
                                                type="button"
                                                className="text-accent-text hover:text-fg rounded-sm underline underline-offset-2"
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
                        <p className="text-fg-subtle text-xs leading-relaxed break-words">
                            {gap.reason}
                        </p>
                    )}
                    {gap?.candidates.map((c) => (
                        <div
                            key={c.key}
                            className="rounded-control border-ok bg-sunken flex flex-wrap items-start justify-between gap-3 border-l-2 px-3 py-2"
                        >
                            <span className="text-fg-muted min-w-0 flex-1 text-sm leading-relaxed break-words">
                                {c.statement}
                            </span>
                            {c.role_id !== undefined && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        onOpen({
                                            type: 'role_fact',
                                            role_id: c.role_id as number,
                                            fact_key: c.key,
                                        })
                                    }
                                >
                                    Use in the editor
                                </Button>
                            )}
                        </div>
                    ))}
                    {gap && gap.adjacent.length > 0 && (
                        <Disclosure
                            quiet
                            summary="Related evidence (not the same thing; do not present it as this)"
                        >
                            <ul className="text-fg-subtle list-disc space-y-1 pl-4 text-xs leading-relaxed break-words">
                                {gap.adjacent.map((a) => (
                                    <li key={a.key}>{a.statement}</li>
                                ))}
                            </ul>
                        </Disclosure>
                    )}
                </div>
            </Disclosure>
        </li>
    );
}

function CoverageBar({
    counts,
}: {
    counts: { strong: number; partial: number; missing: number };
}) {
    const total = counts.strong + counts.partial + counts.missing;
    const items: { key: string; tone: Tone; n: number; label: string }[] = [
        { key: 'strong', tone: 'ok', n: counts.strong, label: 'strong' },
        { key: 'partial', tone: 'warn', n: counts.partial, label: 'partial' },
        { key: 'missing', tone: 'bad', n: counts.missing, label: 'missing' },
    ];

    return (
        <div>
            <div
                role="img"
                aria-label={`${counts.strong} strong, ${counts.partial} partial, ${counts.missing} missing of ${total} requirements`}
                className="bg-sunken flex h-2 gap-0.5 overflow-hidden rounded-full"
            >
                {items.map(
                    (i) =>
                        i.n > 0 && (
                            <span
                                key={i.key}
                                className={DOT[i.tone]}
                                style={{ flexGrow: i.n }}
                            />
                        ),
                )}
            </div>
            <ul className="text-fg-muted mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                {items.map((i) => (
                    <li key={i.key} className="flex items-center gap-2">
                        <span
                            aria-hidden="true"
                            className={cn(
                                'inline-block h-2 w-2 rounded-full',
                                DOT[i.tone],
                            )}
                        />
                        <span className="text-fg font-semibold tabular-nums">
                            {i.n}
                        </span>
                        {i.label}
                    </li>
                ))}
            </ul>
        </div>
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
            <Card className="p-5">
                <p className="text-fg-muted text-sm leading-relaxed">
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
            </Card>
        );
    }

    if (readiness.state === 'none') {
        return (
            <Card className="p-5">
                <h2 className="text-fg text-base font-semibold tracking-tight">
                    Check this version against the job
                </h2>
                <p className="text-fg-muted mt-1.5 max-w-prose text-sm leading-relaxed">
                    See which requirements the resume clearly shows, what it
                    leaves out, what your record can and cannot support, and
                    whether the PDF reads cleanly. It never rewrites anything.
                </p>
                <Button
                    variant="primary"
                    className="mt-4"
                    onClick={start}
                    disabled={starting}
                >
                    Run readiness review
                </Button>
            </Card>
        );
    }

    if (readiness.state === 'failed') {
        return (
            <div className="space-y-3">
                <Alert tone="bad" role="alert">
                    The review did not finish:{' '}
                    {readiness.message ?? 'unknown error'}
                </Alert>
                <Button variant="primary" onClick={start} disabled={starting}>
                    Try again
                </Button>
            </div>
        );
    }

    if (readiness.state === 'stale') {
        return (
            <Alert tone="warn">
                <p className="font-medium">The last review is out of date.</p>
                <ul className="text-fg-muted mt-1 list-disc space-y-0.5 pl-5">
                    {(readiness.stale_reasons ?? []).map((reason) => (
                        <li key={reason}>{reason}</li>
                    ))}
                </ul>
                <Button
                    variant="primary"
                    className="mt-3"
                    onClick={start}
                    disabled={starting}
                >
                    Run a new review
                </Button>
            </Alert>
        );
    }

    const result = readiness.result!;
    const groups: [
        string,
        ReadinessRequirement['coverage'],
        ReadinessRequirement[],
    ][] = [
        [
            'Missing',
            'missing',
            result.requirements.filter((r) => r.coverage === 'missing'),
        ],
        [
            'Partial',
            'partial',
            result.requirements.filter((r) => r.coverage === 'partial'),
        ],
        [
            'Strong',
            'strong',
            result.requirements.filter((r) => r.coverage === 'strong'),
        ],
    ];
    const defects = result.document.findings.filter((f) => f.kind === 'defect');
    const suggestions = result.document.findings.filter(
        (f) => f.kind === 'suggestion',
    );
    const unusedTerms = result.terms.filter(
        (t) => t.status === 'supported_not_in_resume',
    );
    const rating = result.hiring_argument.rating;
    const ratingTone: Tone =
        rating === 'persuasive' ? 'ok' : rating === 'weak' ? 'bad' : 'warn';
    const metrics = readiness.metrics ?? {};

    // One consolidated list of everything worth a second look.
    const allNotes: { key: string; tone: Tone; text: string }[] = [
        ...defects.map((f) => ({
            key: `d-${f.code}`,
            tone: 'bad' as Tone,
            text: f.message,
        })),
        ...result.risks.composition.map((c, i) => ({
            key: `c${i}`,
            tone: 'warn' as Tone,
            text: c.message,
        })),
        ...result.risks.wording.map((w) => ({
            key: `w-${w.label}`,
            tone: 'warn' as Tone,
            text: `${w.label}: ${w.messages.join(' ') || w.status}`,
        })),
        ...result.gaps.map((g) => ({
            key: `g-${g.requirement_id}`,
            tone: 'neutral' as Tone,
            text: `${g.text} (${GAP_LABEL[g.status] ?? g.status}). Your record does not establish this, so it is not wording to add.`,
        })),
    ];
    // The same message can arrive from more than one check; show it once.
    const seen = new Set<string>();
    const notes = allNotes.filter((n) => {
        if (seen.has(n.text)) {
            return false;
        }

        seen.add(n.text);

        return true;
    });

    return (
        <div className="space-y-8">
            <Card className="p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={ratingTone}>
                            {rating === 'persuasive'
                                ? 'Persuasive case'
                                : rating === 'weak'
                                  ? 'Weak case'
                                  : 'Adequate case'}
                        </Badge>
                        <span className="text-fg-subtle text-xs">
                            Reviewed{' '}
                            {readiness.reviewed_at
                                ? new Date(
                                      readiness.reviewed_at,
                                  ).toLocaleString()
                                : ''}
                            . Edits need a new review.
                        </span>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={start}
                        disabled={starting}
                    >
                        Run again
                    </Button>
                </div>
                <p className="text-fg mt-3 max-w-prose text-sm leading-relaxed break-words">
                    {result.hiring_argument.summary}
                </p>
                <div className="mt-4">
                    <CoverageBar counts={result.counts} />
                </div>
                {(result.emphasis.over.length > 0 ||
                    result.emphasis.under.length > 0) && (
                    <p className="text-fg-subtle mt-3 text-xs leading-relaxed">
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
            </Card>

            {result.improvements.length > 0 && (
                <section aria-label="Best improvements">
                    <SectionTitle aside="In order of impact">
                        Best improvements
                    </SectionTitle>
                    <ol className="mt-3 space-y-3">
                        {result.improvements.map((imp, i) => (
                            <li key={i}>
                                <Card className="flex gap-4 p-4">
                                    <span
                                        aria-hidden="true"
                                        className="bg-accent-soft text-accent-text flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                    >
                                        {i + 1}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-fg text-sm font-medium break-words">
                                            {imp.title}
                                        </p>
                                        <p className="text-fg-muted mt-1 text-sm leading-relaxed break-words">
                                            {imp.advice}
                                        </p>
                                        {imp.facts.length > 0 && (
                                            <div className="mt-3 space-y-2">
                                                {imp.facts.map((f) => (
                                                    <EvidenceQuote key={f.key}>
                                                        {f.statement}
                                                    </EvidenceQuote>
                                                ))}
                                            </div>
                                        )}
                                        {imp.target && (
                                            <Button
                                                size="sm"
                                                className="mt-3"
                                                onClick={() =>
                                                    onOpen(imp.target)
                                                }
                                            >
                                                {imp.target.type === 'role_fact'
                                                    ? 'Use in the editor'
                                                    : 'Open in the editor'}
                                            </Button>
                                        )}
                                    </div>
                                </Card>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            <section aria-label="Review notes">
                <SectionTitle
                    aside={
                        notes.length > 0
                            ? `${notes.length} to look at`
                            : undefined
                    }
                >
                    Review notes
                </SectionTitle>
                <Card className="mt-3 p-4">
                    {notes.length === 0 ? (
                        <p className="text-fg flex items-center gap-2 text-sm">
                            <Dot tone="ok" />
                            No document defects or claim risks found in the{' '}
                            {result.document.pages}-page PDF.
                        </p>
                    ) : (
                        <ul className="space-y-2.5">
                            {notes.map((n) => (
                                <li
                                    key={n.key}
                                    className="text-fg flex gap-3 text-sm leading-relaxed break-words"
                                >
                                    <Dot tone={n.tone} />
                                    <span className="min-w-0">{n.text}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {(suggestions.length > 0 || unusedTerms.length > 0) && (
                        <Disclosure
                            quiet
                            className="border-line mt-4 border-t pt-3"
                            summary="Optional document suggestions"
                        >
                            {suggestions.length > 0 && (
                                <ul className="text-fg-muted list-disc space-y-1 pl-4 text-sm leading-relaxed">
                                    {suggestions.map((f) => (
                                        <li key={f.code}>{f.message}</li>
                                    ))}
                                </ul>
                            )}
                            {unusedTerms.length > 0 && (
                                <p className="text-fg-muted mt-2 text-sm leading-relaxed break-words">
                                    Job terms your record supports that the
                                    resume never uses:{' '}
                                    {unusedTerms
                                        .map((t) => t.capability)
                                        .join(', ')}
                                    .
                                </p>
                            )}
                            <p className="text-fg-subtle mt-2 text-xs">
                                These describe the document, not how any
                                applicant tracking system will score it.
                            </p>
                        </Disclosure>
                    )}
                </Card>
            </section>

            <section aria-label="Requirements">
                <SectionTitle aside={`${result.requirements.length} total`}>
                    Requirement coverage
                </SectionTitle>
                <div className="mt-3 space-y-3">
                    {groups.map(
                        ([name, coverage, list]) =>
                            list.length > 0 && (
                                <Card key={name} className="overflow-hidden">
                                    <Disclosure
                                        className="[&>div]:mt-0 [&>div]:pl-0 [&>summary]:px-4 [&>summary]:py-3"
                                        defaultOpen={coverage !== 'strong'}
                                        summary={
                                            <span className="flex items-center gap-2">
                                                <Badge
                                                    tone={
                                                        COVERAGE_TONE[coverage]
                                                    }
                                                >
                                                    {name}
                                                </Badge>
                                                <span className="text-fg-subtle text-xs">
                                                    {list.length}{' '}
                                                    {list.length === 1
                                                        ? 'requirement'
                                                        : 'requirements'}
                                                </span>
                                            </span>
                                        }
                                    >
                                        <ul className="divide-line border-line divide-y border-t">
                                            {list.map((r) => (
                                                <Requirement
                                                    key={r.id}
                                                    r={r}
                                                    result={result}
                                                    onOpen={onOpen}
                                                />
                                            ))}
                                        </ul>
                                    </Disclosure>
                                </Card>
                            ),
                    )}
                </div>
            </section>

            <Disclosure quiet summary="Review details">
                <dl className="text-fg-subtle grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
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
                        <p className="text-fg-muted text-xs font-medium">
                            Where the review was corrected against the resume
                        </p>
                        <ul className="text-fg-subtle mt-1 list-disc space-y-0.5 pl-4 text-xs">
                            {result.risks.adjustments.map((a) => (
                                <li key={a}>{a}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </Disclosure>
        </div>
    );
}
