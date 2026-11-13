import type { ToastContent } from '@/lib/decision-toast';
import type { BatchResult } from '@/types/job-posting';

/** Readable labels for the backend's fixed set of batch reason literals; anything else reads as a sentence fragment. */
const REASON_LABELS: Record<string, string> = {
    in_flight: 'already in flight',
    claim_failed: 'could not be queued',
    analysis_unavailable: 'no analysis available',
};

/** "2 already in flight · 1 no analysis available", in first-seen order, null reasons skipped. */
export function batchReasonLines(result: BatchResult): string[] {
    const counts = new Map<string, number>();

    for (const item of result.items) {
        if (item.reason !== null) {
            counts.set(item.reason, (counts.get(item.reason) ?? 0) + 1);
        }
    }

    return [...counts].map(
        ([reason, n]) =>
            `${n} ${REASON_LABELS[reason] ?? reason.replaceAll('_', ' ').trim()}`,
    );
}

/**
 * What to tell the person after an analyze or match batch was queued. Queuing is all a batch does: the rows show the
 * progress, so this only reports how many were started and why any were not.
 */
export function batchToast(result: BatchResult): ToastContent {
    const verb = result.operation === 'analyze' ? 'Analysis' : 'Matching';
    const notes: string[] = [];

    if (result.skipped > 0) {
        notes.push(`${result.skipped} skipped`);
    }

    if (result.failed > 0) {
        notes.push(`${result.failed} failed`);
    }

    if (result.not_found > 0) {
        notes.push(`${result.not_found} not found`);
    }

    const reasons = batchReasonLines(result);
    const description =
        [...notes, ...reasons].length > 0
            ? [notes.join(', '), ...reasons].filter(Boolean).join(' · ')
            : undefined;

    if (result.started > 0) {
        return {
            kind:
                result.failed > 0 || result.not_found > 0
                    ? 'warning'
                    : result.skipped > 0
                      ? 'info'
                      : 'success',
            title: `${verb} started for ${result.started} ${result.started === 1 ? 'job' : 'jobs'}`,
            description,
        };
    }

    return {
        kind: result.failed > 0 || result.not_found > 0 ? 'error' : 'info',
        title: `${verb} not started`,
        description,
    };
}
