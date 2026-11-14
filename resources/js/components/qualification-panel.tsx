import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { GenerationStatus } from '@/components/generation-status';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useGenerationAttemptPolling } from '@/hooks/use-generation-attempt-polling';
import {
    ELIGIBILITY_TONE,
    VERDICT_TONE,
    noRecommendationText,
    qualificationAction,
    reasonsHeading,
    roleShape,
    stackConcernText,
} from '@/lib/qualification';
import { relativeTimeFromNow } from '@/lib/relative-time';
import { notify } from '@/lib/toast';
import { store as qualify } from '@/routes/jobs/qualification';
import type {
    JobQualificationView,
    QualificationEvidence,
    QualificationResult,
} from '@/types/job-posting';

type Assessment = NonNullable<QualificationResult['assessment']>;

/** Every sentence the assessment cites, once, in the order they appear. Collapsed by default: the answer comes first. */
function EvidenceList({ assessment }: { assessment: Assessment }) {
    const seen = new Set<string>();
    const sentences: QualificationEvidence[] = [];
    const cited = [
        ...assessment.why_it_fits.flatMap((r) => r.evidence),
        ...(assessment.biggest_concern?.evidence ?? []),
        ...assessment.mandatory_stack_concerns.flatMap((t) => t.evidence),
    ];

    for (const e of cited) {
        if (!seen.has(e.ref)) {
            seen.add(e.ref);
            sentences.push(e);
        }
    }

    if (sentences.length === 0) {
        return null;
    }

    return (
        <details className="text-fg-subtle text-xs">
            <summary className="hover:text-fg cursor-pointer rounded-sm">
                From the posting ({sentences.length})
            </summary>
            <ul className="border-line mt-1.5 space-y-1.5 border-l-2 pl-3">
                {sentences.map((e) => (
                    <li key={e.ref} className="break-words">
                        “{e.excerpt}”
                    </li>
                ))}
            </ul>
        </details>
    );
}

function Reasons({ q }: { q: QualificationResult }) {
    return (
        <ul className="space-y-1.5">
            {q.eligibility_reasons.map((r) => (
                <li key={`${r.code}-${r.evidence}`} className="text-sm">
                    <span className="text-fg-muted">{r.message}</span>
                    {r.evidence !== null && (
                        <span className="text-fg-subtle block text-xs break-words">
                            “{r.evidence}”
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

function Result({ q, stale }: { q: QualificationResult; stale: boolean }) {
    const a = q.assessment;

    return (
        <div className="space-y-3 pt-2 text-sm">
            <div className="flex flex-wrap items-center gap-1.5">
                {q.verdict !== null ? (
                    <Badge tone={VERDICT_TONE[q.verdict]}>
                        {q.verdict_label}
                    </Badge>
                ) : (
                    <Badge>No recommendation</Badge>
                )}
                <Badge tone={ELIGIBILITY_TONE[q.eligibility]}>
                    {q.eligibility_label}
                </Badge>
                {stale && <Badge tone="warn">Out of date</Badge>}
            </div>

            {a === null ? (
                <p className="text-fg-muted">
                    {noRecommendationText(q.eligibility)}
                </p>
            ) : (
                <>
                    <div>
                        <p className="text-fg font-medium break-words">
                            {a.primary_role_focus}
                        </p>
                        <p className="text-fg-subtle text-xs">{roleShape(a)}</p>
                    </div>

                    {a.why_it_fits.length > 0 && (
                        <div>
                            <h4 className="text-fg-subtle text-xs font-medium">
                                {reasonsHeading(q.verdict)}
                            </h4>
                            <ul className="text-fg-muted mt-0.5 list-disc space-y-0.5 pl-5">
                                {a.why_it_fits.map((r) => (
                                    <li key={r.text}>{r.text}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {a.biggest_concern !== null && (
                        <div>
                            <h4 className="text-warn text-xs font-medium">
                                Biggest concern
                            </h4>
                            <p className="text-fg-muted mt-0.5">
                                {a.biggest_concern.text}
                            </p>
                        </div>
                    )}

                    {a.mandatory_stack_concerns.length > 0 && (
                        <div>
                            <h4 className="text-fg-subtle text-xs font-medium">
                                Mandatory stack to check
                            </h4>
                            <ul className="text-fg-muted mt-0.5 list-disc space-y-0.5 pl-5">
                                {a.mandatory_stack_concerns.map((t) => (
                                    <li key={t.name}>{stackConcernText(t)}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {a.practical_concerns.length > 0 && (
                        <div>
                            <h4 className="text-fg-subtle text-xs font-medium">
                                Also worth knowing
                            </h4>
                            <ul className="text-fg-muted mt-0.5 list-disc space-y-0.5 pl-5">
                                {a.practical_concerns.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            )}

            {q.eligibility_reasons.length > 0 && (
                <div>
                    <h4 className="text-fg-subtle text-xs font-medium">
                        Eligibility
                    </h4>
                    <div className="mt-0.5">
                        <Reasons q={q} />
                    </div>
                </div>
            )}

            {a !== null && <EvidenceList assessment={a} />}

            <p className="text-fg-subtle text-xs">
                {q.generated_by === null
                    ? 'Checked automatically, no model used'
                    : `Screened by ${q.generated_by}`}{' '}
                · {relativeTimeFromNow(q.generated_at)}
                {q.adjusted &&
                    ` · Adjusted by policy from “${q.model_verdict_label}”`}
            </p>
        </div>
    );
}

/**
 * The drawer's qualification block: a quick screen of fit and eligibility before any full analysis. One button asks for
 * it; progress and the result come back through the same attempt polling analysis uses. Reading it changes nothing about
 * the job: the Shortlist, Dismiss and Pursue actions stay entirely separate.
 */
export function QualificationPanel({
    jobId,
    qualification,
}: {
    jobId: number;
    qualification: JobQualificationView;
}) {
    const attempt = useGenerationAttemptPolling(qualification.attempt);
    const [asking, setAsking] = useState(false);
    const action = qualificationAction(qualification);

    // When an attempt this panel was watching finishes, fetch the drawer's job again to show what it produced.
    const watching = useRef(false);
    useEffect(() => {
        const active =
            attempt?.status === 'queued' || attempt?.status === 'running';

        if (watching.current && !active) {
            router.reload({ only: ['selected_job'] });
        }

        watching.current = active;
    }, [attempt?.status]);

    const ask = () => {
        if (asking || action.disabled) {
            return;
        }

        setAsking(true);
        router.post(
            qualify.url({ jobPosting: jobId }),
            action.refresh ? { refresh: true } : {},
            {
                showProgress: false,
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    notify.error("Couldn't start qualification", {
                        description: Object.values(errors)[0],
                    }),
                onNetworkError: () => {
                    notify.error("Couldn't reach the server", {
                        description: 'Nothing was changed. Try again.',
                    });
                },
                onFinish: () => setAsking(false),
            },
        );
    };

    const inFlight =
        qualification.state === 'queued' || qualification.state === 'running';

    return (
        <section
            aria-labelledby="drawer-qualification"
            className="border-line mb-6 border-b pb-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3
                    id="drawer-qualification"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    Qualification
                </h3>
                <Button
                    size="sm"
                    variant={
                        qualification.state === 'none' ? 'primary' : 'secondary'
                    }
                    disabled={asking || action.disabled}
                    onClick={ask}
                >
                    {action.label}
                </Button>
            </div>

            {qualification.state === 'none' && (
                <p className="text-fg-muted pt-1.5 text-sm">
                    A quick screen of fit and eligibility, before any full
                    analysis. It never changes this job&apos;s decision.
                </p>
            )}

            {inFlight && (
                <GenerationStatus
                    active
                    label="Qualifying"
                    queued={qualification.state === 'queued'}
                    queuedLabel="Qualification queued…"
                    startedAt={attempt?.started_at}
                />
            )}

            {qualification.state === 'failed' && (
                <p role="alert" className="text-bad pt-1.5 text-sm">
                    {qualification.attempt?.failure_message ??
                        'The qualification did not finish.'}{' '}
                    <span className="text-fg-subtle">
                        Nothing about this job changed.
                    </span>
                </p>
            )}

            {qualification.current !== null && (
                <Result q={qualification.current} stale={qualification.stale} />
            )}
        </section>
    );
}
