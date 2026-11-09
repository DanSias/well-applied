import { ApprovedEvidencePanel } from '@/components/approved-evidence-panel';
import { ClaimExpansion } from '@/components/claim-expansion';
import { ConsolidationReview } from '@/components/consolidation-review';
import type {
    InterviewAnswer,
    ResumeProfilePage,
} from '@/types/resume-profile';
import { Badge } from '@/components/ui/badge';
import { ButtonLink } from '@/components/ui/button';
import { index as careerDataIndex } from '@/routes/career-data';
import { Card, Disclosure } from '@/components/ui/surface';

export function AnswerReview({
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

export function AnswersReview({ page }: { page: ResumeProfilePage }) {
    const answers = page.interview.answers;

    if (answers.length === 0) {
        return null;
    }

    const waiting = answers.filter(
        (a) => a.proposal?.status === 'proposed',
    ).length;

    return (
        <section>
            <h2 className="sr-only">Your answers so far</h2>
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

const VERSION_LABEL = (
    v: { status: string; stale: boolean },
    isApproved: boolean,
): { label: string; tone: 'ok' | 'warn' | 'accent' | 'neutral' } => {
    if (v.status === 'draft') {
        return {
            label: v.stale ? 'Draft · evidence changed' : 'Draft',
            tone: 'accent',
        };
    }

    if (v.status === 'approved' && isApproved) {
        return v.stale
            ? { label: 'Approved · needs refresh', tone: 'warn' }
            : { label: 'Approved · in use', tone: 'ok' };
    }

    return { label: 'Replaced by a newer version', tone: 'neutral' };
};

export function HistoryTab({ page }: { page: ResumeProfilePage }) {
    const { synthesis, consolidation, interview } = page;
    const shown = synthesis.synthesis;
    const waitingConsolidation = consolidation.counts.proposed;
    const waitingAnswers = interview.answers.filter(
        (a) => a.proposal?.status === 'proposed',
    ).length;

    return (
        <div className="space-y-4">
            <Card className="px-5 py-4">
                <Disclosure
                    defaultOpen
                    summary={`Version history (${synthesis.versions.length})`}
                >
                    {synthesis.versions.length === 0 ? (
                        <p className="text-fg-muted text-sm">
                            No version has been built yet.
                        </p>
                    ) : (
                        <ul className="divide-line -mx-1 mt-1 divide-y">
                            {synthesis.versions.map((v) => {
                                const status = VERSION_LABEL(
                                    v,
                                    v.version === synthesis.approved_version,
                                );

                                return (
                                    <li
                                        key={v.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-1 py-3 text-sm"
                                    >
                                        <span className="text-fg font-medium">
                                            Version {v.version}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-2">
                                            {v.approved_at && (
                                                <span className="text-fg-subtle text-xs">
                                                    Approved{' '}
                                                    {new Date(
                                                        v.approved_at,
                                                    ).toLocaleDateString()}
                                                </span>
                                            )}
                                            <Badge tone={status.tone}>
                                                {status.label}
                                            </Badge>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {shown && (
                        <Disclosure
                            quiet
                            className="mt-3"
                            summary="Advanced: how this version was built"
                        >
                            <dl className="text-fg-subtle grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                                <div>Shown version: {shown.version}</div>
                                <div>
                                    Evidence revision:{' '}
                                    {shown.profile.provenance.evidence_revision}
                                </div>
                                <div>
                                    Written by: {shown.generated_by ?? 'n/a'}
                                </div>
                                <div>
                                    Prompt version:{' '}
                                    {shown.prompt_version ?? 'n/a'}
                                </div>
                                <div>
                                    Model calls: {shown.model_calls ?? 'n/a'}
                                    {shown.duration_ms !== null &&
                                        `, ${Math.round(shown.duration_ms / 1000)}s`}
                                </div>
                                <div>
                                    Market sample:{' '}
                                    {shown.profile.provenance.sample
                                        ? `${shown.profile.provenance.sample.postings} postings${shown.pilot ? ' (pilot)' : ''}`
                                        : 'none'}
                                </div>
                            </dl>
                        </Disclosure>
                    )}
                </Disclosure>
            </Card>

            <Card className="px-5 py-4">
                <Disclosure
                    defaultOpen={waitingConsolidation > 0}
                    summary={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            Evidence consolidation
                            <span className="text-fg-subtle text-xs font-normal">
                                {waitingConsolidation} waiting ·{' '}
                                {consolidation.counts.approved} approved ·{' '}
                                {consolidation.counts.rejected} declined
                            </span>
                            {waitingConsolidation > 0 && (
                                <Badge tone="warn">Needs your review</Badge>
                            )}
                        </span>
                    }
                >
                    <ConsolidationReview queue={consolidation} />
                </Disclosure>
            </Card>

            <Card className="px-5 py-4">
                <Disclosure
                    defaultOpen={waitingAnswers > 0}
                    summary={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            Your answers
                            <span className="text-fg-subtle text-xs font-normal">
                                {interview.answers.length} answered
                            </span>
                            {waitingAnswers > 0 && (
                                <Badge tone="warn">
                                    {waitingAnswers} waiting for approval
                                </Badge>
                            )}
                        </span>
                    }
                >
                    {interview.answers.length === 0 ? (
                        <p className="text-fg-muted text-sm">No answers yet.</p>
                    ) : (
                        <AnswersReview page={page} />
                    )}
                </Disclosure>
            </Card>

            {page.contributed.facts.length > 0 && (
                <Card className="p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="max-w-prose">
                            <h3 className="text-fg text-sm font-semibold">
                                Evidence you confirmed
                            </h3>
                            <p className="text-fg-muted mt-1 text-sm leading-relaxed">
                                {page.contributed.facts.length} facts from this
                                profile&rsquo;s interview are now part of your
                                career data. Browse, search and see where each
                                came from in Career Data.
                            </p>
                        </div>
                        <ButtonLink
                            href={careerDataIndex.url({
                                query: {
                                    view: 'facts',
                                    provenance: 'interview',
                                },
                            })}
                        >
                            Browse in Career Data
                        </ButtonLink>
                    </div>
                    <Disclosure
                        quiet
                        className="border-line mt-4 border-t pt-3"
                        summary="Revise confirmed evidence"
                    >
                        <ApprovedEvidencePanel evidence={page.contributed} />
                    </Disclosure>
                </Card>
            )}
        </div>
    );
}
