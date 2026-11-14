import type { ToastContent } from '@/lib/decision-toast';
import type { Tone } from '@/components/ui/badge';
import type {
    JobQualificationView,
    QualificationEligibility,
    QualificationFlash,
    QualificationStackConcern,
    QualificationVerdict,
} from '@/types/job-posting';

/**
 * Wording and decisions for the drawer's qualification block, kept out of the component so they can be tested. Nothing
 * here scores or ranks: it names what the server said and which button to offer.
 */
export const VERDICT_TONE: Record<QualificationVerdict, Tone> = {
    strong: 'ok',
    good: 'ok',
    needs_review: 'warn',
    poor: 'bad',
};

export const ELIGIBILITY_TONE: Record<QualificationEligibility, Tone> = {
    eligible: 'ok',
    ineligible: 'bad',
    unknown: 'warn',
    insufficient_information: 'warn',
};

const ROLE_TYPE: Record<string, string> = {
    product_engineering: 'Product engineering',
    frontend: 'Frontend',
    backend: 'Backend',
    full_stack: 'Full-stack',
    data_engineering: 'Data engineering',
    infrastructure_devops: 'Infrastructure / DevOps',
    engineering_management: 'Engineering management',
    other_engineering: 'Other engineering',
    non_engineering: 'Not an engineering role',
};

const HANDS_ON: Record<string, string> = {
    hands_on: 'Hands-on',
    mixed: 'Hands-on and leading',
    primarily_management: 'Primarily management',
    unclear: 'Hands-on level unclear',
};

const SENIORITY: Record<string, string> = {
    junior: 'Junior',
    mid: 'Mid-level',
    senior: 'Senior',
    staff_or_above: 'Staff or above',
    unspecified: 'Seniority not stated',
};

const label = (map: Record<string, string>, value: string) =>
    map[value] ?? value.replaceAll('_', ' ');

export const roleTypeLabel = (value: string) => label(ROLE_TYPE, value);
export const handsOnLabel = (value: string) => label(HANDS_ON, value);
export const seniorityLabel = (value: string) => label(SENIORITY, value);

/** "Role type · hands-on · seniority", the one-line shape of the role. */
export function roleShape(a: {
    role_type: string;
    hands_on: string;
    seniority: string;
}): string {
    return [
        roleTypeLabel(a.role_type),
        handsOnLabel(a.hands_on),
        seniorityLabel(a.seniority),
    ].join(' · ');
}

/** How a mandatory technology the candidate has not shown reads: what it asks for, and whether equivalents are welcome. */
export function stackConcernText(t: QualificationStackConcern): string {
    const asks =
        t.depth === 'deep_production'
            ? 'asks for deep production experience'
            : t.depth === 'working'
              ? 'expects hands-on use'
              : 'required';
    const bridge = t.transferable_accepted
        ? 'equivalent experience welcome'
        : t.familiarity === 'related'
          ? 'a related stack is evidenced'
          : 'not evidenced in your profile';

    return `${t.name}: ${asks}; ${bridge}`;
}

export type QualificationAction = {
    label: string;
    disabled: boolean;
    /** Ask the server to run again even though the stored assessment is still valid. */
    refresh: boolean;
};

/**
 * The one button. A job never screened offers Qualify; one in flight offers nothing; a valid result offers an explicit
 * Re-run (which spends another model call); an out-of-date one offers Re-qualify (the server sees the change on its own);
 * a failure offers Try again (a refresh when the earlier result is still valid, since the server would otherwise decline).
 */
export function qualificationAction(
    q: JobQualificationView,
): QualificationAction {
    const hasFresh = q.current !== null && !q.stale;

    switch (q.state) {
        case 'queued':
        case 'running':
            return { label: 'Qualifying…', disabled: true, refresh: false };
        case 'failed':
            return { label: 'Try again', disabled: false, refresh: hasFresh };
        case 'ready':
            return q.stale
                ? { label: 'Re-qualify', disabled: false, refresh: false }
                : { label: 'Re-run', disabled: false, refresh: true };
        default:
            return { label: 'Qualify', disabled: false, refresh: false };
    }
}

/**
 * The heading over the model's reasons. For a poor match the reasons are the case against the job, so "Why it fits" would
 * read backwards; every other verdict keeps the natural positive wording.
 */
export function reasonsHeading(verdict: QualificationVerdict | null): string {
    return verdict === 'poor' ? "Why it doesn't fit" : 'Why it fits';
}

/** The line shown for a result that gave no recommendation, so the reason is plain. */
export function noRecommendationText(e: QualificationEligibility): string {
    return e === 'ineligible'
        ? 'Not screened further: an explicit requirement does not fit.'
        : 'Not screened further: the posting does not give enough to judge.';
}

/** What to tell the person after they asked for a qualification. */
export function qualificationToast(flash: QualificationFlash): ToastContent {
    switch (flash.outcome) {
        case 'started':
            return { kind: 'info', title: 'Qualifying this job' };
        case 'in_flight':
            return { kind: 'info', title: 'Already being qualified' };
        default:
            return {
                kind: 'info',
                title: 'Already up to date',
                description: 'The job and your profile have not changed.',
            };
    }
}
