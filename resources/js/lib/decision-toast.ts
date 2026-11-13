import { canUndoDecision } from '@/lib/opportunities-list';
import type { DecisionResult } from '@/types/job-posting';

/**
 * What to tell the person after a job decision (one job or many), from the result the server flashed. Pure, so the
 * wording and the severity are testable: success when everything changed, a warning when only some did, an error when
 * nothing could, and info when every job was already there.
 */
export type ToastContent = {
    kind: 'success' | 'info' | 'warning' | 'error';
    title: string;
    description?: string;
    /** Decision ids to hand to the undo endpoint, when the change can be undone. */
    undo?: Record<string, number>;
};

const plural = (n: number, one: string, many: string) => (n === 1 ? one : many);

function changedTitle(to: DecisionResult['to'], n: number): string {
    const jobs = n === 1 ? 'Job' : `${n} jobs`;

    return (
        {
            shortlisted: `${jobs} shortlisted`,
            dismissed: `${jobs} dismissed`,
            pursued: `${jobs} added to Opportunities`,
            new: `${jobs} returned to Inbox`,
            undo: n === 1 ? 'Decision undone' : `${n} decisions undone`,
        }[to] ?? `${jobs} updated`
    );
}

/** The refusal messages, each once, with how many jobs they applied to. */
function refusals(result: DecisionResult): string[] {
    const counts = new Map<string, number>();

    for (const item of result.failed) {
        counts.set(item.message, (counts.get(item.message) ?? 0) + 1);
    }

    return [...counts].map(([message, n]) =>
        n > 1 ? `${n} jobs: ${message}` : message,
    );
}

export function decisionToast(result: DecisionResult): ToastContent {
    const changed = result.changed.length;
    const problems = result.failed.length + result.not_found.length;
    const notes: string[] = [];

    if (result.unchanged.length > 0) {
        notes.push(
            `${result.unchanged.length} already ${plural(result.unchanged.length, 'was', 'were')} there`,
        );
    }

    if (result.not_found.length > 0) {
        notes.push(
            `${result.not_found.length} ${plural(result.not_found.length, 'job', 'jobs')} not found`,
        );
    }

    notes.push(...refusals(result));

    const description = notes.length > 0 ? notes.join(' · ') : undefined;
    const undo = canUndoDecision(result) ? result.decision_ids : undefined;

    if (changed > 0) {
        return {
            kind: problems > 0 ? 'warning' : 'success',
            title: changedTitle(result.to, changed),
            description,
            undo,
        };
    }

    if (problems > 0) {
        return {
            kind: 'error',
            title:
                result.to === 'undo'
                    ? "Couldn't undo that decision"
                    : result.failed.length + result.not_found.length === 1
                      ? "Couldn't update that job"
                      : "Couldn't update those jobs",
            description,
        };
    }

    return { kind: 'info', title: 'Nothing to change', description };
}
