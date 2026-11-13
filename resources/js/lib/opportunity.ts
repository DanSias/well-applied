import type { Tone } from '@/components/ui/badge';
import type { OpportunityTriageStage } from '@/types/job-posting';
import type { InspectionSummary } from '@/types/application-inspection';

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
