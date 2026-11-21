import { Badge } from '@/components/ui/badge';
import type { JobFreshnessView } from '@/types/job-posting';

/**
 * How a job's freshness is shown. Three different things are never confused:
 *   - CONFIRMED OPEN: the provider said so (an ok-toned badge, with the date in the note);
 *   - NOT LIVE-CHECKED / CHECK INCONCLUSIVE: unobtrusive, neutral, and only in the detail note: a job open "as far as our records go";
 *   - EXPIRED / CLOSED / MAY BE CLOSED: the warning tone, reserved for exactly those.
 * Nothing here hides or removes a job, and nothing here sends anything: it only renders what it is given. null (the feature is off) renders nothing.
 */
export function freshnessBadgeLabel(
    freshness: JobFreshnessView | null | undefined,
    warnOnly = false,
): string | null {
    if (!freshness) return null;

    // The neutral kinds are never a badge: they would be noise on a list of jobs that have simply not been checked.
    if (freshness.tone === 'neutral') return null;

    // Lists show only the warning states: a confirmed-open badge on every row would be the noise this avoids.
    if (warnOnly && freshness.tone !== 'warn') return null;

    return freshness.label;
}

export function FreshnessBadge({
    freshness,
    warnOnly = false,
}: {
    freshness: JobFreshnessView | null | undefined;
    /** For list rows: only the warning states (Expired, Closed, May be closed) get a badge. */
    warnOnly?: boolean;
}) {
    const label = freshnessBadgeLabel(freshness, warnOnly);
    if (!freshness || label === null) return null;

    return <Badge tone={freshness.tone}>{label}</Badge>;
}

/**
 * The sentence that goes with a state, for places with room for it (a drawer, a workspace). The badge already says the label, so the note adds only the explanation;
 * the neutral states have no badge, so their note leads with the label. The date is the ISO day, never a locale string.
 */
export function freshnessNote(
    freshness: JobFreshnessView | null | undefined,
): string | null {
    if (!freshness) return null;
    if (freshness.tone === 'neutral') {
        return `${freshness.label}: ${freshness.detail}`;
    }
    if (freshness.state === 'active' && freshness.confirmed_at) {
        return `${freshness.detail.replace(/\.$/, '')} on ${freshness.confirmed_at.slice(0, 10)}.`;
    }

    return freshness.detail;
}

export function FreshnessNote({
    freshness,
}: {
    freshness: JobFreshnessView | null | undefined;
}) {
    const text = freshnessNote(freshness);
    if (text === null || !freshness) return null;

    return (
        <p
            className={
                freshness.tone === 'warn'
                    ? 'text-warn text-xs'
                    : 'text-fg-subtle text-xs'
            }
        >
            {text}
        </p>
    );
}

/**
 * The reason new pursuit work (shortlist, pursue, inspect, tailor a resume, prepare) is unavailable, or null when it is available. It comes from the server's one
 * decision, so the control and the backend refusal always agree.
 */
export function newWorkBlock(
    freshness: JobFreshnessView | null | undefined,
): string | null {
    return freshness?.new_work_block ?? null;
}
