import { Badge } from '@/components/ui/badge';
import type { JobFreshnessView } from '@/types/job-posting';

/**
 * A job the provider says is no longer open is labelled, never hidden or removed here: Expired and Closed (non-actionable) and "May be closed" (a closure not yet
 * confirmed; still actionable). Active, unchecked and a feature that is off (null) show nothing.
 */
export function freshnessBadgeLabel(
    freshness: JobFreshnessView | null | undefined,
): string | null {
    if (!freshness) return null;

    return ['expired', 'closed', 'uncertain'].includes(freshness.state)
        ? freshness.label
        : null;
}

export function FreshnessBadge({
    freshness,
}: {
    freshness: JobFreshnessView | null | undefined;
}) {
    const label = freshnessBadgeLabel(freshness);
    if (label === null) return null;

    return <Badge tone="warn">{label}</Badge>;
}
