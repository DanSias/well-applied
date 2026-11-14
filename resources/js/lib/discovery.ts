import type { Tone } from '@/components/ui/badge';
import type { DiscoveryProviderHealth, HomeDiscovery } from '@/types/home';

/** "3 hours" / "2 days": coarse age of a last success, for sentences like "last succeeded 3 hours ago". */
export function ageLabel(hours: number | null): string {
    if (hours === null) {
        return 'never';
    }
    if (hours < 1) {
        return 'less than an hour ago';
    }
    if (hours < 48) {
        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.floor(hours / 24);

    return `${days} days ago`;
}

/**
 * One honest sentence about discovery as a whole. It never says discovery "is running": the schedule only fires while a
 * scheduler process is up, so the only thing the data can show is when providers last succeeded.
 */
export function discoveryHeadline(discovery: HomeDiscovery): {
    tone: Tone;
    text: string;
} {
    if (!discovery.ever_ran) {
        return {
            tone: 'warn',
            text: 'Discovery has not run yet. Run php artisan discovery:run, or start the scheduler.',
        };
    }

    const stale = discovery.providers.filter((p) => p.stale);

    if (stale.length > 0) {
        return {
            tone: 'warn',
            text: `Discovery has not run recently for ${stale.map((p) => p.label).join(', ')}. The scheduler may not be running.`,
        };
    }

    return {
        tone: 'ok',
        text: 'Every scheduled provider has succeeded recently.',
    };
}

export function providerBadge(provider: DiscoveryProviderHealth): {
    tone: Tone;
    text: string;
} {
    if (provider.last_success === null) {
        return {
            tone: provider.scheduled ? 'warn' : 'neutral',
            text: 'Never succeeded',
        };
    }
    if (provider.stale) {
        return { tone: 'warn', text: 'Overdue' };
    }

    return provider.scheduled
        ? { tone: 'ok', text: 'Recent' }
        : { tone: 'neutral', text: 'Manual only' };
}
