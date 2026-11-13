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
        case 'analyze':
            return job('Analyze job');
        default:
            return job('Prepare resume');
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
