import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { revoke as revokeLimitation } from '@/routes/career-limitations';
import {
    answer as answerQuestion,
    continueMethod as continueGeneration,
    deepen as deepenQuestions,
    skip as skipQuestion,
} from '@/routes/jobs/clarification';
import {
    approve as approveProposal,
    reject as rejectProposal,
} from '@/routes/evidence-proposals';
import type {
    Clarification,
    ClarificationQuestion,
    EvidenceProposal,
} from '@/types/tailored-resume';
import { buttonClass } from '@/components/ui/button';
import { fieldClass } from '@/components/ui/field';
import { cn } from '@/lib/utils';

type Errors = Record<string, string>;

/** Where a question card posts. Job clarification and Resume Profile interviews share the cards. */
export type QuestionRoutes = {
    answer: (questionId: number) => string;
    skip: (questionId: number) => string;
    /** Only profile interviews let a skipped question be picked up again. */
    reopen?: (questionId: number) => string;
};

export const jobQuestionRoutes = (jobId: number): QuestionRoutes => ({
    answer: (questionId) =>
        answerQuestion.url({ jobPosting: jobId, question: questionId }),
    skip: (questionId) =>
        skipQuestion.url({ jobPosting: jobId, question: questionId }),
});

const MODES = [
    { value: 'experience', label: 'I have this experience' },
    { value: 'no_experience', label: 'No direct experience' },
    { value: 'preference', label: 'Presentation guidance only' },
] as const;

const VISIBILITY_HELP: Record<string, string> = {
    public: 'Public: may appear on a resume as written.',
    restricted: 'Restricted: may inform the resume only in generalized terms.',
    private: 'Private: never used on a resume.',
};

const field = cn(fieldClass, 'mt-1 w-full text-sm');
const primary = buttonClass('primary', 'sm');
const secondary = buttonClass('secondary', 'sm');

function ErrorList({ errors }: { errors: Errors }) {
    const messages = Object.values(errors);

    if (messages.length === 0) {
        return null;
    }

    return (
        <ul role="alert" className="mt-2 space-y-1 text-xs text-red-600">
            {messages.map((message) => (
                <li key={message}>{message}</li>
            ))}
        </ul>
    );
}

function AnswerForm({
    routes,
    question,
    clarification,
}: {
    routes: QuestionRoutes;
    question: ClarificationQuestion;
    clarification: Clarification;
}) {
    const [mode, setMode] =
        useState<(typeof MODES)[number]['value']>('experience');
    const [scopes, setScopes] = useState<string[]>([]);
    const [text, setText] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);

    const toggle = (value: string) =>
        setScopes((current) =>
            current.includes(value)
                ? current.filter((s) => s !== value)
                : [...current, value],
        );

    const submit = () => {
        setBusy(true);
        router.post(
            routes.answer(question.id),
            {
                mode,
                scopes: mode === 'experience' ? scopes : [],
                answer_text: text,
            },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => setErrors({}),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="mt-3 space-y-3">
            <fieldset className="flex flex-wrap gap-4 text-sm">
                <legend className="sr-only">Kind of answer</legend>
                {MODES.map((m) => (
                    <label key={m.value} className="flex items-center gap-1.5">
                        <input
                            type="radio"
                            name={`mode-${question.id}`}
                            checked={mode === m.value}
                            onChange={() => setMode(m.value)}
                        />
                        {m.label}
                    </label>
                ))}
            </fieldset>

            {mode === 'experience' && (
                <fieldset>
                    <legend className="text-xs font-medium text-neutral-700 dark:text-neutral-300">
                        What did you do? Pick everything that is true. The
                        wording will not claim more.
                    </legend>
                    <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        {clarification.scopes.map((scope) => (
                            <label
                                key={scope.value}
                                className="flex items-center gap-1.5"
                            >
                                <input
                                    type="checkbox"
                                    checked={scopes.includes(scope.value)}
                                    onChange={() => toggle(scope.value)}
                                />
                                {scope.label}
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}

            <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                {mode === 'no_experience'
                    ? 'Anything to add (optional)'
                    : mode === 'preference'
                      ? 'How should your existing experience be presented?'
                      : 'In your own words'}
                <textarea
                    className={field}
                    rows={3}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                />
            </label>

            <ErrorList errors={errors} />

            <div className="flex gap-2">
                <button
                    type="button"
                    disabled={busy}
                    onClick={submit}
                    className={primary}
                >
                    Save answer
                </button>
                <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                        router.post(
                            routes.skip(question.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    className={secondary}
                >
                    Skip this question
                </button>
            </div>
        </div>
    );
}

export function ProposalCard({
    proposal,
    clarification,
}: {
    proposal: EvidenceProposal;
    clarification: Clarification;
}) {
    const [statement, setStatement] = useState(proposal.statement);
    const [scopes, setScopes] = useState<string[]>(proposal.scopes);
    const [attribution, setAttribution] = useState(
        proposal.attributable_type && proposal.attributable_id
            ? `${proposal.attributable_type}:${proposal.attributable_id}`
            : '',
    );
    const [visibility, setVisibility] = useState(proposal.visibility);
    const [verification, setVerification] = useState(proposal.verification);
    const [supersede, setSupersede] = useState(false);
    const [revoke, setRevoke] = useState(false);
    const [acknowledge, setAcknowledge] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);

    const kindLabel = {
        fact: 'Proposed career fact',
        limitation: 'Proposed known limitation',
        preference: 'Proposed presentation guidance',
    }[proposal.kind];

    if (proposal.status === 'approved') {
        return (
            <div className="mt-3 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                <p className="font-medium">
                    Approved:{' '}
                    {proposal.kind === 'fact'
                        ? 'added to your career facts'
                        : proposal.kind === 'limitation'
                          ? 'recorded as a known limitation'
                          : 'saved as presentation guidance'}
                </p>
                <p className="mt-1">{proposal.statement}</p>
                {proposal.kind === 'fact' && (
                    <p className="mt-1 text-xs opacity-80">
                        {proposal.attribution_label} · {proposal.visibility} ·{' '}
                        {proposal.verification.replace('_', ' ')}
                        {proposal.career_fact_key &&
                            ` · ${proposal.career_fact_key}`}
                    </p>
                )}
            </div>
        );
    }

    if (proposal.status === 'rejected') {
        return (
            <p className="mt-3 text-sm text-neutral-500">
                Rejected. Nothing was saved.
            </p>
        );
    }

    const isFact = proposal.kind === 'fact';
    const [type, id] = attribution.split(':');

    const payload = () => ({
        statement,
        ...(isFact
            ? {
                  scopes,
                  attributable_type: type || null,
                  attributable_id: id ? Number(id) : null,
                  visibility,
                  verification,
              }
            : {}),
        supersede,
        revoke_limitation: revoke,
        acknowledge_metric: acknowledge,
    });

    const act = (url: string, data: RequestPayload) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    const message = Object.values(errors).join(' ');

    return (
        <div className="mt-3 rounded-md border border-blue-300 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100">
            <p className="font-medium">
                {kindLabel}: review before it is saved
            </p>
            <p className="mt-1 text-xs opacity-80">
                Your answer, verbatim: &ldquo;{proposal.answer_text}&rdquo;
            </p>

            <label className="mt-2 block text-xs font-medium">
                Statement (edit to the wording you want on record)
                <textarea
                    className={field}
                    rows={3}
                    value={statement}
                    onChange={(e) => setStatement(e.target.value)}
                />
            </label>

            {isFact && (
                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                    <fieldset className="sm:col-span-2">
                        <legend className="text-xs font-medium">
                            Responsibility scope
                        </legend>
                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                            {clarification.scopes.map((scope) => (
                                <label
                                    key={scope.value}
                                    className="flex items-center gap-1.5"
                                >
                                    <input
                                        type="checkbox"
                                        checked={scopes.includes(scope.value)}
                                        onChange={() =>
                                            setScopes((c) =>
                                                c.includes(scope.value)
                                                    ? c.filter(
                                                          (s) =>
                                                              s !== scope.value,
                                                      )
                                                    : [...c, scope.value],
                                            )
                                        }
                                    />
                                    {scope.label}
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <label className="text-xs font-medium sm:col-span-2">
                        Belongs to
                        <select
                            className={field}
                            value={attribution}
                            onChange={(e) => setAttribution(e.target.value)}
                        >
                            <option value="">Choose a role or project</option>
                            {clarification.attribution_options.map((o) => (
                                <option
                                    key={`${o.type}:${o.id}`}
                                    value={`${o.type}:${o.id}`}
                                >
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="text-xs font-medium">
                        Visibility
                        <select
                            className={field}
                            value={visibility}
                            onChange={(e) =>
                                setVisibility(
                                    e.target.value as typeof visibility,
                                )
                            }
                        >
                            <option value="public">Public</option>
                            <option value="restricted">Restricted</option>
                            <option value="private">Private</option>
                        </select>
                        <span className="mt-0.5 block font-normal opacity-80">
                            {VISIBILITY_HELP[visibility]}
                        </span>
                    </label>

                    <label className="text-xs font-medium">
                        Verification
                        <select
                            className={field}
                            value={verification}
                            onChange={(e) =>
                                setVerification(
                                    e.target.value as typeof verification,
                                )
                            }
                        >
                            <option value="strongly_supported">
                                Strongly supported (your own account)
                            </option>
                            <option value="verified">
                                Verified (you can substantiate it)
                            </option>
                            <option value="needs_confirmation">
                                Needs confirmation
                            </option>
                        </select>
                    </label>
                </div>
            )}

            {verification === 'verified' && isFact && (
                <label className="mt-2 flex items-center gap-1.5 text-xs">
                    <input
                        type="checkbox"
                        checked={acknowledge}
                        onChange={(e) => setAcknowledge(e.target.checked)}
                    />
                    I can substantiate any figures in this statement
                </label>
            )}

            {/already approved/.test(message) && (
                <label className="mt-2 flex items-center gap-1.5 text-xs">
                    <input
                        type="checkbox"
                        checked={supersede}
                        onChange={(e) => setSupersede(e.target.checked)}
                    />
                    Replace the existing fact with this wording
                </label>
            )}
            {/previously confirmed/.test(message) && (
                <label className="mt-2 flex items-center gap-1.5 text-xs">
                    <input
                        type="checkbox"
                        checked={revoke}
                        onChange={(e) => setRevoke(e.target.checked)}
                    />
                    Revoke the known limitation, my experience has changed
                </label>
            )}

            <ErrorList errors={errors} />

            <div className="mt-3 flex gap-2">
                <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                        act(
                            approveProposal.url({
                                evidenceProposal: proposal.id,
                            }),
                            payload(),
                        )
                    }
                    className={primary}
                >
                    {isFact
                        ? 'Approve and add to career facts'
                        : proposal.kind === 'limitation'
                          ? 'Approve as known limitation'
                          : 'Approve guidance'}
                </button>
                <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                        act(
                            rejectProposal.url({
                                evidenceProposal: proposal.id,
                            }),
                            {},
                        )
                    }
                    className={secondary}
                >
                    Reject
                </button>
            </div>
        </div>
    );
}

export function QuestionCard({
    routes,
    question,
    clarification,
}: {
    routes: QuestionRoutes;
    question: ClarificationQuestion;
    clarification: Clarification;
}) {
    return (
        <li className="rounded-md border border-neutral-200 p-3 dark:border-neutral-800">
            <p className="text-xs font-medium tracking-wide text-neutral-500 uppercase">
                {question.topic_label}
                {question.why.kind && (
                    <span className="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 tracking-normal normal-case dark:bg-neutral-800">
                        {question.why.kind === 'deepening'
                            ? 'Go deeper'
                            : 'New to your record'}
                    </span>
                )}
            </p>
            <p className="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                {question.text}
            </p>

            <details className="mt-2 text-xs text-neutral-600 dark:text-neutral-400">
                <summary className="cursor-pointer">Why this is asked</summary>
                {question.why.market && (
                    <p className="mt-1">
                        In the market sample: {question.why.market.postings} of{' '}
                        {question.why.market.sample_postings} postings (
                        {Math.round(question.why.market.share * 100)}%),{' '}
                        {Math.round(question.why.market.required_share * 100)}%
                        as a stated requirement.
                    </p>
                )}
                <p className="mt-1">
                    Your record: {question.why.coverage?.replaceAll('_', ' ')}
                    {question.why.strength && ` · ${question.why.strength}`}
                    {question.why.emphasis &&
                        ` · ${question.why.emphasis} emphasis`}
                    {' · '}value score {question.score}
                </p>
                {question.why.related_evidence.length > 0 && (
                    <ul className="mt-1 list-disc space-y-1 pl-4">
                        {question.why.related_evidence.map((fact) => (
                            <li key={fact.key}>
                                ({fact.visibility}, {fact.relationship}){' '}
                                {fact.statement}
                            </li>
                        ))}
                    </ul>
                )}
            </details>

            {question.proposal ? (
                <ProposalCard
                    key={`${question.proposal.id}-${question.proposal.history.length}`}
                    proposal={question.proposal}
                    clarification={clarification}
                />
            ) : question.status === 'covered' ? (
                <p className="mt-3 text-sm text-neutral-500">
                    Your record now covers this, so there is nothing to ask.
                </p>
            ) : question.status === 'retired' ? (
                <p className="mt-3 text-sm text-neutral-500">
                    The latest market sample no longer makes this a priority.
                </p>
            ) : question.status === 'skipped' ? (
                <p className="mt-3 text-sm text-neutral-500">
                    Skipped.{' '}
                    {routes.reopen && (
                        <button
                            type="button"
                            className="underline underline-offset-2"
                            onClick={() =>
                                router.post(
                                    routes.reopen!(question.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Answer now
                        </button>
                    )}
                </p>
            ) : (
                <AnswerForm
                    routes={routes}
                    question={question}
                    clarification={clarification}
                />
            )}
        </li>
    );
}

/**
 * The optional clarification stage, shown in place of the progress list
 * while the pipeline waits between matching and Selection. Everything on it
 * is persisted server-side, so a reload shows the same questions, answers
 * and proposals. Generate Now is always available.
 */
export function ClarificationPanel({
    jobId,
    clarification,
    returnTo,
}: {
    jobId: number;
    clarification: Clarification;
    returnTo?: 'opportunity';
}) {
    const [busy, setBusy] = useState(false);
    const answered = clarification.questions.some((q) => q.status !== 'open');
    const undecided = clarification.questions.filter(
        (q) => q.proposal?.status === 'proposed',
    ).length;

    const post = (url: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="mt-3 space-y-3">
            <div className="rounded-md bg-neutral-50 p-3 text-sm text-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">
                <p className="font-medium text-neutral-900 dark:text-neutral-100">
                    These optional questions could make this resume stronger.
                </p>
                <p className="mt-1 text-xs">
                    Your answers become proposals you review. Nothing is added
                    to your career facts until you approve it, and nothing
                    private is ever shown on a resume. Approved evidence is used
                    for this resume and every future one; matching is refreshed
                    to include it.
                </p>
            </div>

            {clarification.questions.length === 0 ? (
                <p className="text-sm text-neutral-500">
                    No further questions for this job.
                </p>
            ) : (
                <ol className="space-y-3">
                    {clarification.questions.map((question) => (
                        <QuestionCard
                            key={question.id}
                            routes={jobQuestionRoutes(jobId)}
                            question={question}
                            clarification={clarification}
                        />
                    ))}
                </ol>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                        post(
                            continueGeneration.url({ jobPosting: jobId }),
                            returnTo ? { return_to: returnTo } : {},
                        )
                    }
                    className={primary}
                >
                    {clarification.resume_ready
                        ? answered
                            ? 'Continue and refresh the resume'
                            : 'Close and keep the current resume'
                        : answered
                          ? 'Continue generating'
                          : 'Generate Now'}
                </button>
                {answered && (
                    <span className="text-xs text-neutral-500">
                        {undecided > 0
                            ? `${undecided} proposal${undecided === 1 ? '' : 's'} not yet decided will be left out.`
                            : 'Approved evidence will be included.'}
                    </span>
                )}
                {clarification.can_deepen && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() =>
                            post(deepenQuestions.url({ jobPosting: jobId }))
                        }
                        className={secondary}
                    >
                        Ask more questions (deeper discovery)
                    </button>
                )}
            </div>

            {(clarification.limitations.length > 0 ||
                clarification.preferences.length > 0) && (
                <details className="text-xs text-neutral-600 dark:text-neutral-400">
                    <summary className="cursor-pointer">
                        On record: {clarification.limitations.length} known
                        limitation
                        {clarification.limitations.length === 1
                            ? ''
                            : 's'},{' '}
                        {clarification.preferences.length} presentation
                        preference
                        {clarification.preferences.length === 1 ? '' : 's'}
                    </summary>
                    <ul className="mt-2 space-y-1">
                        {clarification.limitations.map((limitation) => (
                            <li key={`l${limitation.id}`}>
                                {limitation.topic}:{' '}
                                {limitation.status.replaceAll('_', ' ')} (
                                {limitation.confirmed_at}){' '}
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() =>
                                        router.delete(
                                            revokeLimitation.url({
                                                careerLimitation: limitation.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Revise: my experience has changed
                                </button>
                            </li>
                        ))}
                        {clarification.preferences.map((preference) => (
                            <li key={`p${preference.id}`}>
                                {preference.topic}: {preference.guidance}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
