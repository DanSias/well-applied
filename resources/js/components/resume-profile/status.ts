import type { ResumeProfilePage } from '@/types/resume-profile';

/**
 * What a person needs to know about a Resume Profile, derived only from state
 * the server already reports. Nothing here decides anything the server
 * enforces: "usable" mirrors the existing rule that an approved version whose
 * evidence has not changed since approval can be used for new resumes, and a
 * stale one cannot.
 */
export type StatusInput = {
    building: boolean;
    hasSample: boolean;
    interview: { total: number; open: number };
    approvedVersion: number | null;
    /** The approved version's evidence has changed since it was approved. */
    approvedStale: boolean;
    /** How evidence is assembled has changed since the shown version was built (it is still usable). */
    outdated: boolean;
    draftVersion: number | null;
    draftStale: boolean;
};

export type Freshness = 'none' | 'current' | 'refresh_available' | 'stale';

export type NextAction =
    | 'wait'
    | 'build_market'
    | 'interview'
    | 'build_profile'
    | 'review_draft'
    | 'refresh'
    | 'use';

export type ProfileStatus = {
    state: 'building' | 'not_started' | 'in_progress' | 'draft' | 'approved';
    /** An approved version exists and its evidence is current: new resumes can use it. */
    usable: boolean;
    freshness: Freshness;
    next: NextAction;
};

export function profileStatus(i: StatusInput): ProfileStatus {
    const usable = i.approvedVersion !== null && !i.approvedStale;
    const hasVersion = i.approvedVersion !== null || i.draftVersion !== null;
    const stale = i.draftVersion !== null ? i.draftStale : i.approvedStale;

    const freshness: Freshness = !hasVersion
        ? 'none'
        : stale
          ? 'stale'
          : i.outdated
            ? 'refresh_available'
            : 'current';

    let next: NextAction;

    if (i.building) {
        next = 'wait';
    } else if (!i.hasSample && !hasVersion) {
        next = 'build_market';
    } else if (i.draftVersion !== null) {
        // A refreshed or edited draft is waiting for approval; a stale draft must be refreshed first.
        next = i.draftStale ? 'refresh' : 'review_draft';
    } else if (i.approvedVersion !== null) {
        next = i.approvedStale ? 'refresh' : 'use';
    } else {
        next = i.interview.open > 0 ? 'interview' : 'build_profile';
    }

    const state: ProfileStatus['state'] = i.building
        ? 'building'
        : i.approvedVersion !== null
          ? 'approved'
          : i.draftVersion !== null
            ? 'draft'
            : i.hasSample
              ? 'in_progress'
              : 'not_started';

    return { state, usable, freshness, next };
}

/** The same input from the profile detail page's props. */
export function statusInputFromPage(page: ResumeProfilePage): StatusInput {
    const { synthesis, interview } = page;
    const shown = synthesis.synthesis;
    const approved = synthesis.versions.find((v) => v.status === 'approved');

    return {
        building: page.build.state === 'building' || synthesis.building,
        hasSample: page.build.state === 'ready',
        interview: {
            total: interview.progress.total,
            open: interview.progress.open,
        },
        approvedVersion: synthesis.approved_version,
        approvedStale: approved?.stale ?? false,
        outdated: shown?.status === 'approved' ? shown.outdated : false,
        draftVersion: shown?.status === 'draft' ? shown.version : null,
        draftStale: shown?.status === 'draft' ? shown.stale : false,
    };
}

export const STATE_LABEL: Record<ProfileStatus['state'], string> = {
    building: 'Building',
    not_started: 'Not started',
    in_progress: 'In progress',
    draft: 'Draft',
    approved: 'Approved',
};

export const NEXT_LABEL: Record<NextAction, string> = {
    wait: 'Working…',
    build_market: 'Study the market',
    interview: 'Continue the interview',
    build_profile: 'Build the profile',
    review_draft: 'Review and approve the draft',
    refresh: 'Refresh evidence',
    use: 'Choose an opportunity',
};
