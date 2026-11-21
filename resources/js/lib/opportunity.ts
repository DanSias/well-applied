import type { Tone } from '@/components/ui/badge';
import type {
    OpportunityTriageStage,
    ProviderAttribution,
} from '@/types/job-posting';
import type { SubmissionIndicator } from '@/lib/submission';
import type { InspectionSummary } from '@/types/application-inspection';
import type { TailoredResumeStatus } from '@/types/tailored-resume';

/**
 * Pure presentation rules for the Opportunities page. An opportunity is a pursued JobPosting; the only state shown is
 * its resume's (none / in progress / final), exactly what the Overview counts. Kept free of React so it can be tested.
 */

export type ResumeState = 'none' | 'in-progress' | 'final';
export type OpportunityResumeFilter = 'all' | ResumeState;

export type NextKind =
    | 'answer_questions'
    | 'review_final'
    | 'continue_resume'
    | 'processing'
    | 'analyze'
    | 'prepare_resume';

export type Opportunity = {
    id: number;
    title: string;
    company: string;
    location: string | null;
    remote_status: string | null;
    compensation_min: number | null;
    compensation_max: number | null;
    compensation_currency: string | null;
    source_url: string | null;
    attribution: ProviderAttribution | null;
    posting_status: 'open' | 'closed';
    pursued_at: string | null;
    stage: OpportunityTriageStage;
    resume: {
        state: ResumeState;
        versions: number;
        current_variant_id: number | null;
        final_variant_id: number | null;
    };
    inspection: InspectionSummary;
    /** An application the person recorded themselves, or null. Never the browser inspection above. */
    submission: SubmissionIndicator | null;
    pending_questions: boolean;
    next: NextKind;
};

/** A job that has resume or inspection work but is not pursued (see ReportJobsWithWork). */
export type ExistingWork = {
    job_id: number;
    title: string;
    company: string;
    disposition: 'new' | 'shortlisted' | 'dismissed' | 'pursued';
    resume_variants: number;
    current_resume_variant_id: number | null;
    final_resume_variant_id: number | null;
    applications: { id: number; status: string }[];
    clarification_sessions: number;
};

export const RESUME_FILTERS: {
    value: OpportunityResumeFilter;
    label: string;
}[] = [
    { value: 'all', label: 'All' },
    { value: 'none', label: 'No resume' },
    { value: 'in-progress', label: 'In progress' },
    { value: 'final', label: 'Final' },
];

/** The default view stays out of the URL, like the Jobs list. */
export function buildOpportunityParams(filter: string): Record<string, string> {
    return filter === 'all' ? {} : { resume: filter };
}

export function resumeStateLabel(state: ResumeState): string {
    return {
        none: 'No resume yet',
        'in-progress': 'Resume in progress',
        final: 'Final resume chosen',
    }[state];
}

export function resumeStateTone(state: ResumeState): Tone {
    return { none: 'neutral', 'in-progress': 'accent', final: 'ok' }[
        state
    ] as Tone;
}

/**
 * The Resume section's one-line state. The persisted resume-generation status (the same object the button and stepper
 * read) wins while a run is going or has stopped; otherwise the resumes the opportunity already has decide. There is no
 * second copy of the run's state: this only chooses a label for it.
 */
export function resumeSummary(
    resume: Pick<Opportunity['resume'], 'state' | 'versions'>,
    generation: Pick<TailoredResumeStatus, 'state'> | null,
): { label: string; tone: Tone } {
    const versions =
        resume.versions > 1 ? ` · ${resume.versions} versions` : '';

    switch (generation?.state) {
        case 'running':
            return { label: `Generating${versions}`, tone: 'accent' };
        case 'interrupted':
            return { label: `Interrupted${versions}`, tone: 'warn' };
        case 'failed':
            return { label: `Generation failed${versions}`, tone: 'bad' };
        case 'needs_clarification':
            return { label: `Questions to answer${versions}`, tone: 'warn' };
        default: {
            // A finished run has made a resume even if this page's own props predate it.
            const state =
                resume.state === 'none' && generation?.state === 'ready'
                    ? 'in-progress'
                    : resume.state;

            return {
                label:
                    state === 'none'
                        ? 'Not started'
                        : `${resumeStateLabel(state)}${versions}`,
                tone: resumeStateTone(state),
            };
        }
    }
}

/** Where a next step leads: the job's own page, or a resume in the existing resume workspace. */
export type NextAction = {
    label: string;
    to: 'job' | 'resume';
    variantId: number | null;
};

export function nextAction(
    op: Pick<Opportunity, 'next' | 'resume'>,
): NextAction {
    const job = (label: string): NextAction => ({
        label,
        to: 'job',
        variantId: null,
    });

    switch (op.next) {
        case 'answer_questions':
            return job('Answer questions');
        case 'review_final':
            return op.resume.final_variant_id === null
                ? job('Review resume')
                : {
                      label: 'Review final resume',
                      to: 'resume',
                      variantId: op.resume.final_variant_id,
                  };
        case 'continue_resume':
            return op.resume.current_variant_id === null
                ? job('Continue resume')
                : {
                      label: 'Continue resume',
                      to: 'resume',
                      variantId: op.resume.current_variant_id,
                  };
        case 'processing':
            return job('View progress');
        // Analysis is the first stage of the tailored resume pipeline, so a job that still needs it gets the same single
        // step as one that needs a resume: generate it. Analyzing on its own stays available, as a secondary action.
        case 'analyze':
        default:
            return job('Generate tailored resume');
    }
}

const count = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

/** What work a not-yet-pursued job already has, in words. An application here is an inspection draft, never a submission. */
export function existingWorkSummary(work: ExistingWork): string {
    const parts: string[] = [];

    if (work.final_resume_variant_id !== null) {
        parts.push('Final resume chosen');
    }

    if (work.resume_variants > 0) {
        parts.push(
            count(work.resume_variants, 'resume version', 'resume versions'),
        );
    }

    if (work.applications.length > 0) {
        parts.push(
            count(
                work.applications.length,
                'inspection draft',
                'inspection drafts',
            ),
        );
    }

    if (work.clarification_sessions > 0) {
        parts.push('questions started');
    }

    return parts.join(' · ');
}

/** What the Opportunity workspace adds to a list row: the posting text and a short pointer to the latest analysis. */
export type OpportunityDetail = Opportunity & {
    description: string;
    description_completeness: 'complete' | 'preview' | 'unknown';
    captured_at: string | null;
};

export type AnalysisPointer = {
    id: number;
    generated_at: string;
    seniority: string | null;
    role_summary: string;
    findings_count: number;
    match: { id: number; generated_at: string; findings_count: number } | null;
} | null;

/**
 * The workspace's one next step: the same choice the list makes (nextAction), shown in place. A step that leads to a
 * resume in the resume workspace is a link there (the one primary button). Every other step happens in the Resume
 * section of this very page, whose own button is the primary action, so the step is stated in words and not repeated as
 * a second button. The hint follows the pipeline's real state, and says so when the description needs confirming.
 */
export type WorkspaceNext =
    | { kind: 'link'; label: string; hint: string; variantId: number }
    | { kind: 'section'; label: string; hint: string };

export type PipelineHint = {
    state:
        | 'idle'
        | 'running'
        | 'ready'
        | 'failed'
        | 'interrupted'
        | 'needs_clarification';
    needs_confirmation: boolean;
};

export function workspaceNext(
    op: Pick<Opportunity, 'next' | 'resume'>,
    pipeline?: PipelineHint,
): WorkspaceNext {
    const next = nextAction(op);

    if (next.to === 'resume' && next.variantId !== null) {
        return {
            kind: 'link',
            label: next.label,
            variantId: next.variantId,
            hint:
                op.next === 'review_final'
                    ? 'Your final resume is chosen. Check it once more before you apply.'
                    : 'Pick up where you left off in the resume workspace.',
        };
    }

    const confirm = pipeline?.needs_confirmation
        ? ' You will be asked to confirm first, because the job description may be incomplete.'
        : '';
    const hints: Record<string, string> = {
        answer_questions:
            'A few questions could improve the resume. Answer them below, or generate now.',
        processing: 'The job is being prepared. Progress is shown below.',
        analyze: `One click analyzes the job, matches your evidence and writes the resume.${confirm}`,
        prepare_resume: `One click matches your evidence and writes the resume.${confirm}`,
    };
    const hint =
        pipeline?.state === 'running'
            ? 'Generation is running. Progress is shown below.'
            : pipeline?.state === 'failed'
              ? 'The last attempt failed. Retry it below; finished stages are reused.'
              : pipeline?.state === 'interrupted'
                ? 'Generation stopped when the worker did. Continue it below; finished stages are kept.'
                : (hints[op.next] ?? 'Continue with the resume below.');

    return { kind: 'section', label: next.label, hint };
}

/**
 * The header's one primary action, chosen from the opportunity's own next step (workspaceNext) and the live
 * resume-generation status the page already holds. It adds no state of its own: a run that is going, stopped or waiting
 * for answers decides first, then a resume the opportunity already has, then a finished run, then generating.
 *
 * - `link`: open a resume in the resume workspace.
 * - `url`: open the finished resume's own review page.
 * - `generate`: run the Resume step's action (start, retry or continue), including its confirmation when the
 *   description may be incomplete.
 * - `anchor`: the step happens in the Resume step below (answering questions), so the button takes you there.
 * - `busy`: a run is in progress; nothing to press.
 */
export type HeaderAction =
    | { kind: 'link'; label: string; variantId: number }
    | { kind: 'url'; label: string; url: string }
    | { kind: 'generate'; label: string }
    | { kind: 'anchor'; label: string }
    | { kind: 'busy'; label: string };

export function headerAction(
    op: Pick<Opportunity, 'next' | 'resume'>,
    generation: Pick<
        TailoredResumeStatus,
        'state' | 'needs_confirmation' | 'profile_resume' | 'preview_url'
    >,
): HeaderAction {
    const next = workspaceNext(op, generation);

    switch (generation.state) {
        case 'running':
            return { kind: 'busy', label: 'Generating…' };
        case 'needs_clarification':
            return { kind: 'anchor', label: 'Answer questions' };
        case 'interrupted':
            return { kind: 'generate', label: 'Continue generating' };
        case 'failed':
            return { kind: 'generate', label: 'Retry generation' };
    }

    if (next.kind === 'link') {
        return {
            kind: 'link',
            label: next.label,
            variantId: next.variantId,
        };
    }

    if (generation.state === 'ready') {
        const url =
            generation.profile_resume?.review_url ?? generation.preview_url;

        if (url) {
            return { kind: 'url', label: 'Review resume', url };
        }
    }

    if (op.next === 'answer_questions' || op.next === 'processing') {
        return { kind: 'anchor', label: next.label };
    }

    return { kind: 'generate', label: 'Generate tailored resume' };
}
