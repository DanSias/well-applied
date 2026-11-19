import type {
    TailoredResumeStage,
    TailoredResumeStageKey,
    TailoredResumeStatus,
} from '@/types/tailored-resume';

/**
 * The three user-facing steps of "Generate Tailored Resume", derived from the persisted pipeline stages in the unified
 * status (never from timers). The server reports up to four stages: a run from the original matching pathway has a
 * separate evidence-match stage, which is part of composing the resume here, and a profile-assisted run has none. A
 * step's state is the worst-first combination of its stages, so a failure or interruption is never hidden.
 */
export type StepState =
    | 'done'
    | 'active'
    | 'pending'
    | 'failed'
    | 'interrupted';

export type ResumeStep = {
    key: 'analyze' | 'compose' | 'validate';
    label: string;
    state: StepState;
    /** Why a step is active or stopped, when the server says so more precisely than the label (e.g. "waiting for worker"). */
    note: string | null;
};

const GROUPS: {
    key: ResumeStep['key'];
    label: string;
    stages: TailoredResumeStageKey[];
}[] = [
    { key: 'analyze', label: 'Analyze requirements', stages: ['analysis'] },
    { key: 'compose', label: 'Compose resume', stages: ['match', 'resume'] },
    { key: 'validate', label: 'Validate PDF', stages: ['pdf'] },
];

const SATISFIED: TailoredResumeStage['status'][] = ['done', 'reused'];

function combine(
    stages: TailoredResumeStage[],
    runInProgress: boolean,
): StepState {
    const statuses = stages.map((s) => s.status);

    if (statuses.includes('failed')) {
        return 'failed';
    }

    if (statuses.includes('interrupted')) {
        return 'interrupted';
    }

    if (statuses.some((s) => s === 'running' || s === 'queued')) {
        return 'active';
    }

    if (statuses.every((s) => SATISFIED.includes(s))) {
        return 'done';
    }

    // Part of the step finished and the rest is about to be queued: it is the step in progress, but only while the run is.
    if (runInProgress && statuses.some((s) => SATISFIED.includes(s))) {
        return 'active';
    }

    return 'pending';
}

/** The three steps for a status, or none when there is no run to show (nothing started, or held for questions). */
export function resumeSteps(
    status: Pick<TailoredResumeStatus, 'state' | 'stages'>,
): ResumeStep[] {
    if (status.stages.length === 0 || status.state === 'idle') {
        return [];
    }

    const running = status.state === 'running';

    return GROUPS.map((group) => {
        const stages = status.stages.filter((s) =>
            group.stages.includes(s.key),
        );

        // The step is absent from the run entirely (nothing maps to it): it has not happened.
        const state: StepState =
            stages.length === 0 ? 'pending' : combine(stages, running);
        const current = stages.find((s) =>
            ['running', 'queued', 'failed', 'interrupted'].includes(s.status),
        );

        return {
            key: group.key,
            label: group.label,
            state,
            note:
                current?.status === 'queued'
                    ? 'waiting for worker'
                    : (current?.phase ?? null),
        };
    });
}
