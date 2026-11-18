import { Badge } from '@/components/ui/badge';
import { ScreenshotGallery } from '@/components/preparation/screenshot-gallery';
import {
    presentPreparation,
    UNLABELLED,
    uploadExplanation,
    valueEvidence,
} from '@/lib/preparation-status';
import type {
    PreparationReview,
    ReviewQuestion,
} from '@/types/application-preparation';

const shortHash = (sha: string | null) =>
    sha === null ? 'unknown' : `${sha.slice(0, 12)}…`;

function Count({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'ok' | 'warn' | 'bad';
}) {
    const color =
        value === 0
            ? 'text-fg-subtle'
            : tone === 'ok'
              ? 'text-ok'
              : tone === 'warn'
                ? 'text-warn'
                : tone === 'bad'
                  ? 'text-bad'
                  : 'text-fg';

    return (
        <div className="border-line bg-raised rounded-md border px-3 py-2">
            <dd className={`text-lg leading-none font-semibold ${color}`}>
                {value}
            </dd>
            <dt className="text-fg-muted mt-1 text-xs">{label}</dt>
        </div>
    );
}

function QuestionList({
    title,
    items,
    note,
    open = false,
}: {
    title: string;
    items: ReviewQuestion[];
    note?: string;
    open?: boolean;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <details open={open} className="border-line rounded-md border">
            <summary className="text-fg cursor-pointer px-3 py-2 text-sm font-medium">
                {title}{' '}
                <span className="text-fg-subtle font-normal">
                    ({items.length})
                </span>
            </summary>
            {note && <p className="text-fg-muted px-3 pb-1 text-xs">{note}</p>}
            <ul className="border-line divide-line divide-y border-t">
                {items.map((q, i) => {
                    const value = valueEvidence({
                        entered: q.entered ?? null,
                        intended: q.intended ?? null,
                        page_selected: q.page_selected ?? [],
                        provenance: q.provenance ?? { source: null, key: null },
                    });

                    return (
                        <li
                            key={`${q.label ?? 'unlabelled'}-${i}`}
                            className="px-3 py-2 text-sm"
                        >
                            <div className="flex items-start justify-between gap-3">
                                {q.label === null ? (
                                    <span className="text-fg-subtle italic">
                                        {UNLABELLED}
                                    </span>
                                ) : (
                                    <span className="text-fg min-w-0 break-words">
                                        {q.label}
                                    </span>
                                )}
                                <span className="text-fg-subtle shrink-0 text-xs">
                                    {q.kind ?? 'field'}
                                    {q.required === null
                                        ? ''
                                        : q.required
                                          ? ' · required'
                                          : ' · optional'}
                                </span>
                            </div>
                            {value && (
                                <p className="mt-1 text-xs">
                                    <span
                                        className="text-fg font-medium break-all"
                                        data-value-kind={value.kind}
                                    >
                                        {value.kind === 'intended'
                                            ? 'Intended: '
                                            : value.kind === 'page_default'
                                              ? 'Page default: '
                                              : ''}
                                        {value.text}
                                    </span>
                                    <span className="text-fg-subtle block">
                                        {value.caption}
                                    </span>
                                </p>
                            )}
                            {q.note && !value && (
                                <p className="text-fg-subtle mt-1 text-xs break-words">
                                    {q.note}
                                </p>
                            )}
                            {q.note && value && value.kind === 'intended' && (
                                <p className="text-fg-subtle mt-0.5 text-xs break-words">
                                    {q.note}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>
        </details>
    );
}

/**
 * One finished attempt, read as a summary first and details on demand. Everything here comes from the attempt's own
 * review (labels, kinds and statuses: never what was typed), and it says outright that nothing was submitted.
 */
export function PreparationReviewView({
    review,
}: {
    review: PreparationReview;
}) {
    const notSubmitted = (
        <p
            role="note"
            className="border-line bg-raised text-fg-muted rounded-md border px-3 py-2 text-sm"
        >
            <strong className="text-fg font-medium">Not submitted.</strong>{' '}
            {review.submission.statement}
        </p>
    );

    if (!review.available) {
        return (
            <div className="space-y-3">
                {notSubmitted}
                <p className="text-fg-muted text-sm">
                    This attempt delivered no report to review
                    {review.failure_message
                        ? `: ${review.failure_message}`
                        : '.'}
                </p>
            </div>
        );
    }

    const failures = review.safety_events.filter((e) => e.failure);
    const background = review.safety_events.filter((e) => !e.failure);
    const upload = review.resume.upload ?? null;
    const required = review.needs_answer.length;
    const counts = review.counts;
    const verified = counts.filledVerified ?? review.verified.length;
    const presentation = presentPreparation({
        application_id: 0,
        workflow_run: null,
        attempt: {
            agent_run_id: review.agent_run_id,
            status: review.status,
            queued_at: null,
            started_at: null,
            phase: null,
            failure_category: null,
            inputs: null,
        },
        attempts: [],
        latest_result: {
            agent_run_id: review.agent_run_id,
            finished_at: null,
            outcome: review.outcome,
            reviewable: review.reviewable,
            integrity_flags: review.integrity_flags,
            counts,
        },
    });

    return (
        <div className="space-y-4">
            <div>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={presentation.headline.tone}>
                        {presentation.headline.label}
                    </Badge>
                    {review.ats && (
                        <span className="text-fg-subtle text-xs capitalize">
                            {review.ats}
                        </span>
                    )}
                </div>
                <p className="text-fg-muted mt-1.5 text-sm">
                    {presentation.explanation}
                </p>
            </div>

            {notSubmitted}

            {failures.length > 0 && (
                <div
                    role="alert"
                    className="border-bad-line bg-bad-soft text-bad rounded-md border px-3 py-2 text-sm"
                >
                    <p className="font-medium">Safety events</p>
                    <ul className="mt-1 list-disc pl-5">
                        {failures.map((e, i) => (
                            <li key={i} className="break-words">
                                {e.kind.replaceAll('_', ' ')}: {e.detail}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {review.integrity_flags.length > 0 && (
                <p
                    role="note"
                    className="border-warn-line bg-warn-soft text-warn rounded-md border px-3 py-2 text-sm"
                >
                    Evidence check:{' '}
                    {review.integrity_flags
                        .map((f) => f.replaceAll('_', ' '))
                        .join(', ')}
                    .
                </p>
            )}

            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <Count label="Filled and verified" value={verified} tone="ok" />
                <Count label="Need your answer" value={required} tone="warn" />
                <Count
                    label="Need a person"
                    value={review.needs_person.length}
                    tone="warn"
                />
                <Count
                    label="Need a check"
                    value={review.needs_check.length}
                    tone="warn"
                />
                <Count label="Errors" value={review.failed.length} tone="bad" />
                <Count
                    label="Optional, skipped"
                    value={review.optional_skipped.length}
                />
            </dl>

            <section aria-labelledby="prep-resume">
                <h3
                    id="prep-resume"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    Resume
                </h3>
                <dl className="mt-1.5 space-y-1.5">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                        <dt className="text-fg-subtle text-xs">
                            Tailored resume
                        </dt>
                        <dd className="text-fg text-sm">
                            version {review.resume.variant_id ?? 'unknown'}{' '}
                            <span
                                className="text-fg-subtle font-mono text-xs"
                                title={review.resume.sha256 ?? undefined}
                            >
                                {shortHash(review.resume.sha256)}
                            </span>
                        </dd>
                    </div>
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                        <dt className="text-fg-subtle text-xs">Verification</dt>
                        <dd>
                            <Badge
                                tone={review.resume.verified ? 'ok' : 'warn'}
                            >
                                {review.resume.verified
                                    ? 'Verified'
                                    : 'Not verified'}
                            </Badge>
                        </dd>
                    </div>
                </dl>
                <p className="text-fg-muted mt-1.5 text-sm">
                    {uploadExplanation(upload?.outcome)}
                    {upload?.http_status ? ` (HTTP ${upload.http_status})` : ''}
                </p>
                {review.resume.reported_sha256_matches === false && (
                    <p className="text-bad mt-1 text-sm">
                        The file the worker reports is not the one it was given.
                    </p>
                )}
                <p className="text-fg-subtle mt-1 text-xs">
                    This is the exact file snapshotted when the attempt started.
                </p>
            </section>

            <section aria-labelledby="prep-questions" className="space-y-2">
                <h3
                    id="prep-questions"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    The form
                </h3>
                <QuestionList
                    title="Needs your answer"
                    items={review.needs_answer}
                    note="Required questions nothing could answer for you."
                    open
                />
                <QuestionList
                    title="Needs a person"
                    items={review.needs_person}
                    note="Actions only a person can take, such as choosing a location suggestion."
                    open
                />
                <QuestionList
                    title="Needs a check"
                    items={review.needs_check}
                    note="A value is there but could not be confirmed, or the page chose one itself."
                />
                <QuestionList title="Errors" items={review.failed} />
                <QuestionList
                    title="Filled and verified"
                    items={review.verified}
                />
                <QuestionList
                    title="Optional, skipped"
                    items={review.optional_skipped}
                />
            </section>

            {(review.reasons.length > 0 ||
                review.warnings.length > 0 ||
                background.length > 0) && (
                <details className="border-line rounded-md border">
                    <summary className="text-fg cursor-pointer px-3 py-2 text-sm font-medium">
                        Technical details
                    </summary>
                    <div className="border-line text-fg-muted space-y-2 border-t px-3 py-2 text-xs">
                        {review.reasons.length > 0 && (
                            <div>
                                <p className="text-fg font-medium">Why</p>
                                <ul className="list-disc pl-5">
                                    {review.reasons.map((r, i) => (
                                        <li key={i} className="break-words">
                                            {r}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {review.warnings.length > 0 && (
                            <div>
                                <p className="text-fg font-medium">Warnings</p>
                                <ul className="list-disc pl-5">
                                    {review.warnings.map((w, i) => (
                                        <li key={i} className="break-words">
                                            {w}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {background.length > 0 && (
                            <p>
                                {background.length} background request
                                {background.length === 1 ? '' : 's'} (analytics,
                                the resume upload and similar) were handled by
                                the safety guard; none was a submission.
                            </p>
                        )}
                    </div>
                </details>
            )}

            <section aria-labelledby="prep-shots">
                <h3
                    id="prep-shots"
                    className="text-fg mb-1.5 text-sm font-semibold tracking-tight"
                >
                    Screenshots
                </h3>
                <ScreenshotGallery screenshots={review.screenshots} />
            </section>
        </div>
    );
}
