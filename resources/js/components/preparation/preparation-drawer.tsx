import { useState } from 'react';
import { PreparationReviewView } from '@/components/preparation/preparation-review';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { useElapsedTimer } from '@/hooks/use-elapsed-timer';
import type { useApplicationPreparation } from '@/hooks/use-application-preparation';
import { usePreparationReview } from '@/hooks/use-preparation-review';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import { formatElapsedTime } from '@/lib/format-elapsed-time';
import { notify } from '@/lib/toast';
import {
    phaseLabel,
    presentAttempt,
    presentPreparation,
} from '@/lib/preparation-status';
import { relativeTimeFromNow } from '@/lib/relative-time';
import type {
    PreparationAttemptRow,
    PreparationPanel,
    PreparationResumeOption,
} from '@/types/application-preparation';

type Preparation = ReturnType<typeof useApplicationPreparation>;

const day = (iso: string) => new Date(iso).toLocaleDateString();

function Intro() {
    return (
        <details className="border-line rounded-md border">
            <summary className="text-fg cursor-pointer px-3 py-2 text-sm font-medium">
                Inspection, preparation and submission
            </summary>
            <dl className="border-line text-fg-muted space-y-1.5 border-t px-3 py-2 text-xs">
                <div>
                    <dt className="text-fg font-medium">Inspection</dt>
                    <dd>
                        Reads the form&apos;s fields and questions. It never
                        fills in or submits anything.
                    </dd>
                </div>
                <div>
                    <dt className="text-fg font-medium">Preparation (this)</dt>
                    <dd>
                        Fills in the form with your approved details and
                        attaches your resume, then stops. It never submits, and
                        you cannot take over or edit answers here yet.
                    </dd>
                </div>
                <div>
                    <dt className="text-fg font-medium">Submission</dt>
                    <dd>
                        Sending the application. That is a separate step you do
                        yourself, and it has not happened.
                    </dd>
                </div>
            </dl>
        </details>
    );
}

function Elapsed({
    since,
    active,
    prefix,
}: {
    since: string | null;
    active: boolean;
    prefix: string;
}) {
    const seconds = useElapsedTimer(active, since);

    return (
        <span className="text-fg-subtle text-xs tabular-nums">
            {prefix} {formatElapsedTime(seconds)}
        </span>
    );
}

function Progress({ prep }: { prep: Preparation }) {
    const { panel } = prep;
    const state = panel.state;
    const attempt = state?.attempt ?? null;
    const view = presentPreparation(state);
    const queued = attempt?.status === 'queued';
    const worker = useWorkerStatusPolling('application_preparer', queued);

    if (attempt === null || !view.active) {
        return null;
    }

    return (
        <section
            aria-labelledby="prep-progress"
            className="border-line rounded-md border px-3 py-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3
                    id="prep-progress"
                    className="text-fg text-sm font-semibold tracking-tight"
                >
                    {queued
                        ? 'Waiting for a worker'
                        : phaseLabel(attempt.phase)}
                </h3>
                <Elapsed
                    since={
                        queued
                            ? attempt.queued_at
                            : (attempt.started_at ?? attempt.queued_at)
                    }
                    active
                    prefix={queued ? 'Queued for' : 'Running for'}
                />
            </div>
            <p className="text-fg-muted mt-1 text-sm" aria-live="polite">
                {view.explanation}
            </p>
            {queued && (
                <p
                    className={
                        'mt-2 text-xs ' +
                        (worker.online ? 'text-fg-subtle' : 'text-warn')
                    }
                >
                    Preparation worker {worker.online ? 'online' : 'offline'}
                    {worker.last_seen_at
                        ? ` · last seen ${relativeTimeFromNow(worker.last_seen_at)}`
                        : ''}
                    {!worker.online &&
                        '. The attempt stays queued until it connects.'}
                </p>
            )}
            {view.canCancel && state?.workflow_run && (
                <Button
                    size="sm"
                    variant="danger"
                    className="mt-3"
                    disabled={prep.busy !== null}
                    onClick={async () => {
                        const done = await prep.cancel(state.workflow_run!.id);

                        if (done) {
                            notify.info('Preparation cancelled', {
                                description:
                                    'It had not started. Nothing was prepared or submitted.',
                            });
                        }
                    }}
                >
                    {prep.busy === 'cancel'
                        ? 'Cancelling…'
                        : 'Cancel this attempt'}
                </Button>
            )}
        </section>
    );
}

function ResumeChoice({
    options,
    selected,
    onSelect,
    defaultKind,
}: {
    options: PreparationResumeOption[];
    selected: number | null;
    onSelect: (id: number) => void;
    defaultKind: PreparationPanel['resumes']['default_kind'];
}) {
    return (
        <fieldset>
            <legend className="text-fg text-sm font-semibold tracking-tight">
                Tailored resume
            </legend>
            <p className="text-fg-muted mt-0.5 text-xs">
                {defaultKind === 'final'
                    ? 'Your final resume is selected.'
                    : 'No resume is marked final, so your current one is selected.'}{' '}
                The PDF is copied when you start, so later edits will not change
                this attempt.
            </p>
            <ul className="mt-2 space-y-1.5">
                {options.map((option) => (
                    <li key={option.id}>
                        <label className="border-line has-[:checked]:border-accent/60 has-[:checked]:bg-accent-soft flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2">
                            <input
                                type="radio"
                                name="preparation-resume"
                                className="mt-0.5"
                                checked={selected === option.id}
                                onChange={() => onSelect(option.id)}
                            />
                            <span className="min-w-0 text-sm">
                                <span className="text-fg font-medium">
                                    {option.label}
                                </span>{' '}
                                {option.is_final && (
                                    <Badge tone="ok">Final</Badge>
                                )}{' '}
                                {option.is_current && !option.is_final && (
                                    <Badge tone="accent">Current</Badge>
                                )}
                                <span className="text-fg-subtle block text-xs">
                                    {day(option.generated_at)} ·{' '}
                                    {option.summary}
                                </span>
                            </span>
                        </label>
                    </li>
                ))}
            </ul>
        </fieldset>
    );
}

function StartPanel({ prep, label }: { prep: Preparation; label: string }) {
    const { panel } = prep;
    const view = presentPreparation(panel.state);
    const [chosen, setChosen] = useState<number | null>(
        panel.resumes.default_id,
    );
    const selected = chosen ?? panel.resumes.default_id;

    if (panel.problem !== null) {
        return (
            <section
                aria-labelledby="prep-start"
                className="border-warn-line bg-warn-soft rounded-md border px-3 py-3"
            >
                <h3 id="prep-start" className="text-warn text-sm font-semibold">
                    Preparation cannot start
                </h3>
                <p className="text-fg mt-1 text-sm">{panel.problem.message}</p>
            </section>
        );
    }

    if (!panel.can_prepare) {
        return null;
    }

    return (
        <section aria-labelledby="prep-start" className="space-y-3">
            <h3
                id="prep-start"
                className="text-fg text-sm font-semibold tracking-tight"
            >
                {view.startLabel === 'Prepare application'
                    ? 'Prepare this application'
                    : 'Start a new attempt'}
            </h3>
            {panel.destination && (
                <dl className="border-line rounded-md border px-3 py-2 text-sm">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                        <dt className="text-fg-subtle text-xs">Job</dt>
                        <dd className="text-fg min-w-0 break-words">{label}</dd>
                    </div>
                    <div className="mt-1 flex flex-wrap items-baseline justify-between gap-x-3">
                        <dt className="text-fg-subtle text-xs">
                            Application form
                        </dt>
                        <dd className="text-fg min-w-0 font-mono text-xs break-all">
                            {panel.destination.host}
                            {panel.destination.path}
                        </dd>
                    </div>
                </dl>
            )}
            <ResumeChoice
                options={panel.resumes.options}
                selected={selected}
                onSelect={setChosen}
                defaultKind={panel.resumes.default_kind}
            />
            <p className="text-fg-subtle text-xs">
                Only your approved contact details are used. Anything not on
                your profile (such as LinkedIn) is left for you, never guessed.
                It stops before submitting.
            </p>
            {prep.error && (
                <p
                    role="alert"
                    className="border-bad-line bg-bad-soft text-bad rounded-md border px-3 py-2 text-sm"
                >
                    {prep.error.message}
                </p>
            )}
            <Button
                variant="primary"
                disabled={prep.busy !== null || selected === null}
                onClick={() => void prep.start(selected)}
            >
                {prep.busy === 'start' ? 'Starting…' : view.startLabel}
            </Button>
        </section>
    );
}

function History({
    attempts,
    latestId,
    viewing,
    onView,
}: {
    attempts: PreparationAttemptRow[];
    latestId: number | null;
    viewing: number | null;
    onView: (id: number) => void;
}) {
    const earlier = attempts.filter((a) => a.agent_run_id !== latestId);

    if (earlier.length === 0) {
        return null;
    }

    return (
        <details className="border-line rounded-md border">
            <summary className="text-fg cursor-pointer px-3 py-2 text-sm font-medium">
                Earlier attempts{' '}
                <span className="text-fg-subtle font-normal">
                    ({earlier.length})
                </span>
            </summary>
            <p className="text-fg-subtle px-3 pb-1 text-xs">
                Each attempt is a separate try at preparing this one form. None
                of them is a submitted application.
            </p>
            <ul className="border-line divide-line divide-y border-t">
                {earlier.map((a) => {
                    const badge = presentAttempt(a);
                    const reviewable =
                        a.status === 'succeeded' &&
                        a.outcome !== null &&
                        a.outcome !== 'unsupported';

                    return (
                        <li
                            key={a.agent_run_id}
                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span className="min-w-0 text-sm">
                                <Badge tone={badge.tone}>{badge.label}</Badge>{' '}
                                <span className="text-fg-subtle text-xs">
                                    {a.queued_at ? day(a.queued_at) : ''} ·
                                    resume version{' '}
                                    {a.resume_variant_id ?? 'unknown'}
                                </span>
                            </span>
                            {reviewable && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-pressed={viewing === a.agent_run_id}
                                    onClick={() => onView(a.agent_run_id)}
                                >
                                    {viewing === a.agent_run_id
                                        ? 'Viewing'
                                        : 'View'}
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </details>
    );
}

function Review({ agentRunId }: { agentRunId: number }) {
    const load = usePreparationReview(agentRunId, true);

    if (load === null || load.status === 'loading') {
        return <p className="text-fg-subtle text-sm">Loading the review…</p>;
    }

    if (load.status === 'error') {
        return (
            <p className="text-bad text-sm">
                The review could not be loaded. Nothing was changed.
            </p>
        );
    }

    return <PreparationReviewView review={load.review} />;
}

/**
 * The preparation drawer: start an attempt (with the resume it will use), watch it, read what it found, and look back at
 * earlier attempts. Preparation only: it cannot edit answers, take over the browser or submit.
 */
export function PreparationDrawer({
    prep,
    title,
    company,
    onClose,
}: {
    prep: Preparation;
    title: string;
    company: string;
    onClose: () => void;
}) {
    const { panel } = prep;
    const state = panel.state;
    const view = presentPreparation(state);
    const attempts = state?.attempts ?? [];
    const latest = state?.attempt ?? null;
    const latestId = latest?.agent_run_id ?? null;
    const [viewing, setViewing] = useState<number | null>(null);
    const shown = viewing ?? (view.hasReview ? latestId : null);
    const shownIsLatest = shown === latestId;

    return (
        <Drawer
            title="Prepare application"
            subtitle={`${title} · ${company}`}
            closeLabel="Close application preparation"
            onClose={onClose}
            header={
                <div className="flex flex-wrap items-center gap-2 px-5 pt-2.5">
                    <Badge tone={view.headline.tone}>
                        {view.headline.label}
                    </Badge>
                    <span className="text-fg-subtle text-xs">
                        Preparation only · nothing is submitted
                    </span>
                </div>
            }
        >
            <div className="space-y-4">
                <Intro />
                <Progress prep={prep} />
                {!view.active &&
                    state !== null &&
                    latest !== null &&
                    !view.hasReview && (
                        <p
                            className={
                                'rounded-md border px-3 py-2 text-sm ' +
                                (view.kind === 'failed'
                                    ? 'border-bad-line bg-bad-soft text-bad'
                                    : 'border-line bg-raised text-fg-muted')
                            }
                        >
                            {view.explanation}
                        </p>
                    )}
                {shown !== null && (
                    <section aria-labelledby="prep-review">
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h3
                                id="prep-review"
                                className="text-fg text-sm font-semibold tracking-tight"
                            >
                                {shownIsLatest
                                    ? 'Latest attempt'
                                    : 'Earlier attempt'}
                            </h3>
                            {!shownIsLatest && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setViewing(null)}
                                >
                                    Back to the latest
                                </Button>
                            )}
                        </div>
                        <Review key={shown} agentRunId={shown} />
                    </section>
                )}
                <StartPanel
                    prep={prep}
                    label={`${title} at ${company}`}
                    key={latestId ?? 'new'}
                />
                <History
                    attempts={attempts}
                    latestId={latestId}
                    viewing={shown}
                    onView={setViewing}
                />
            </div>
        </Drawer>
    );
}
