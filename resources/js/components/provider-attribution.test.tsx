import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import type { InspectionStarter } from '@/hooks/use-start-inspection';
import { jobOf } from '@/lib/inspection-test-data';
import type { ProviderAttribution } from '@/types/job-posting';
import { InspectionDrawer, workspaceDestination } from './inspection-drawer';
import {
    GENERIC_SOURCE_LABEL,
    ProviderCredit,
    sourceLinkLabel,
} from './provider-attribution';

const jobicy: ProviderAttribution = {
    provider: 'jobicy',
    name: 'Jobicy',
    home_url: 'https://jobicy.com',
    link_label: 'View on Jobicy ↗',
    notice: 'Listing provided by',
    kind: 'canonical_listing',
};
const himalayas: ProviderAttribution = {
    provider: 'himalayas',
    name: 'Himalayas',
    home_url: 'https://himalayas.app',
    link_label: 'Apply on Himalayas ↗',
    notice: 'Job provided by',
    kind: 'apply_link',
};

describe('provider attribution', () => {
    it("labels a provider link as the provider's, and any other link generically", () => {
        expect(sourceLinkLabel(jobicy)).toBe('View on Jobicy ↗');
        expect(sourceLinkLabel(himalayas)).toBe('Apply on Himalayas ↗');
        expect(sourceLinkLabel(null)).toBe(GENERIC_SOURCE_LABEL);
        expect(sourceLinkLabel(undefined)).toBe('Original posting ↗');
    });

    it('shows a visible credit linking to the provider, and nothing for an unattributed source', () => {
        const html = renderToStaticMarkup(
            <ProviderCredit attribution={himalayas} />,
        );
        expect(html).toContain('Job provided by');
        expect(html).toContain('href="https://himalayas.app"');
        expect(html).toContain('>Himalayas<');
        expect(
            renderToStaticMarkup(<ProviderCredit attribution={null} />),
        ).toBe('');
    });

    const drawer = (attribution: ProviderAttribution | null, url: string) =>
        renderToStaticMarkup(
            <InspectionDrawer
                state="ready"
                job={jobOf(null, { source_url: url, attribution })}
                fallback={null}
                destination={workspaceDestination}
                starter={
                    { starting: false, start: vi.fn() } as InspectionStarter
                }
                onClose={() => {}}
            />,
        );

    it('keeps the stored URL exactly, whatever it is called: a Himalayas apply link is used unchanged', () => {
        const url =
            'https://himalayas.app/companies/acme/jobs/x-1?ref=abc&utm_source=feed';
        const html = drawer(himalayas, url);
        expect(html).toContain('Apply on Himalayas ↗');
        expect(html).toContain(`href="${url.replace(/&/g, '&amp;')}"`);
        expect(html).toContain('Job provided by');
        expect(html).not.toContain('Original posting');
    });

    it('calls a Jobicy link the Jobicy listing, with the credit', () => {
        const html = drawer(jobicy, 'https://jobicy.com/jobs/1-engineer');
        expect(html).toContain('View on Jobicy ↗');
        expect(html).toContain('Listing provided by');
    });

    it('keeps the credit but the generic link label when canonical enrichment replaced the provider link', () => {
        const html = drawer(
            {
                ...jobicy,
                link_label: 'Original posting ↗',
                kind: 'credit_only',
            },
            'https://boards.greenhouse.io/acme/jobs/1',
        );
        expect(html).toContain('Original posting ↗');
        expect(html).toContain('Listing provided by');
        expect(html).not.toContain('View on Jobicy');
    });

    it('keeps the generic wording, and no credit, for a source without an attribution rule', () => {
        const html = drawer(null, 'https://example.com/apply');
        expect(html).toContain('Original posting ↗');
        expect(html).not.toContain('provided by');
    });
});
