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
): string | null {
    if (!freshness) return null;

    // The neutral kinds are never a badge: they would be noise on a list of jobs that have simply not been checked.
    return freshness.tone === 'neutral' ? null : freshness.label;
}

export function FreshnessBadge({
    freshness,
}: {
    freshness: JobFreshnessView | null | undefined;
}) {
    const label = freshnessBadgeLabel(freshness);
    if (!freshness || label === null) return null;

    return <Badge tone={freshness.tone}>{label}</Badge>;
}

/** The sentence that goes with a state, for places with room for it (a drawer, a workspace). Stable text: the date is the ISO day, never a locale string. */
export function freshnessNote(
    freshness: JobFreshnessView | null | undefined,
): string | null {
    if (!freshness) return null;
    const when =
        freshness.state === 'active' && freshness.confirmed_at
            ? ` (${freshness.confirmed_at.slice(0, 10)})`
            : '';

    return `${freshness.label}${when}: ${freshness.detail}`;
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
