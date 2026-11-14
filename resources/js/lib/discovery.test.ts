import { describe, expect, it } from 'vitest';
import { ageLabel, discoveryHeadline, providerBadge } from '@/lib/discovery';
import type { DiscoveryProviderHealth, HomeDiscovery } from '@/types/home';

function provider(
    overrides: Partial<DiscoveryProviderHealth> = {},
): DiscoveryProviderHealth {
    return {
        provider: 'jobicy',
        label: 'Jobicy',
        scheduled: true,
        stale: false,
        last_success: {
            at: '2026-11-14T13:00:00Z',
            age_hours: 3,
            retrieved: 100,
            accepted: 8,
            created: 5,
            updated: 3,
            filtered: 92,
            filtered_by_reason: [],
            retrieved_basis: 'feed',
            normalization_dropped: 0,
        },
        latest: null,
        ...overrides,
    };
}

const discovery = (
    providers: DiscoveryProviderHealth[],
    everRan = true,
): HomeDiscovery => ({
    providers,
    active_rejections: 0,
    stale: providers.some((p) => p.stale),
    ever_ran: everRan,
});

describe('ageLabel', () => {
    it('reads coarse ages', () => {
        expect(ageLabel(null)).toBe('never');
        expect(ageLabel(0)).toBe('less than an hour ago');
        expect(ageLabel(1)).toBe('1 hour ago');
        expect(ageLabel(30)).toBe('30 hours ago');
        expect(ageLabel(72)).toBe('3 days ago');
    });
});

describe('discoveryHeadline', () => {
    it('says discovery has not run when nothing ever has', () => {
        const headline = discoveryHeadline(
            discovery(
                [provider({ last_success: null, latest: null, stale: true })],
                false,
            ),
        );

        expect(headline.tone).toBe('warn');
        expect(headline.text).toContain('has not run yet');
    });

    it('names overdue providers and never claims discovery is running', () => {
        const headline = discoveryHeadline(
            discovery([
                provider({ stale: true }),
                provider({ label: 'Adzuna' }),
            ]),
        );

        expect(headline.tone).toBe('warn');
        expect(headline.text).toContain('Jobicy');
        expect(headline.text).not.toContain('Adzuna');
        expect(headline.text).not.toMatch(/is running/i);
    });

    it('is quiet when every scheduled provider succeeded recently', () => {
        expect(discoveryHeadline(discovery([provider()])).tone).toBe('ok');
    });
});

describe('providerBadge', () => {
    it('distinguishes never-succeeded, overdue, recent and manual-only', () => {
        expect(providerBadge(provider({ last_success: null })).text).toBe(
            'Never succeeded',
        );
        expect(providerBadge(provider({ stale: true })).text).toBe('Overdue');
        expect(providerBadge(provider()).text).toBe('Recent');
        expect(providerBadge(provider({ scheduled: false })).text).toBe(
            'Manual only',
        );
    });
});
