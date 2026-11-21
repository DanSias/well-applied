import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import type { JobFreshnessView } from '@/types/job-posting';
import { FreshnessBadge, freshnessBadgeLabel } from './job-freshness';

const view = (
    state: JobFreshnessView['state'],
    label: string,
): JobFreshnessView => ({
    state,
    label,
    actionable: state !== 'expired' && state !== 'closed',
    reason: 'test',
});

describe('freshness badge', () => {
    it('labels Expired, Closed and "May be closed", and nothing else', () => {
        expect(freshnessBadgeLabel(view('expired', 'Expired'))).toBe('Expired');
        expect(freshnessBadgeLabel(view('closed', 'Closed'))).toBe('Closed');
        expect(freshnessBadgeLabel(view('uncertain', 'May be closed'))).toBe(
            'May be closed',
        );
        expect(freshnessBadgeLabel(view('active', 'Open'))).toBeNull();
        expect(
            freshnessBadgeLabel(view('unchecked', 'Not checked')),
        ).toBeNull();
    });

    it('shows nothing when the feature is off (null), so the page is exactly as before', () => {
        expect(freshnessBadgeLabel(null)).toBeNull();
        expect(freshnessBadgeLabel(undefined)).toBeNull();
        expect(renderToStaticMarkup(<FreshnessBadge freshness={null} />)).toBe(
            '',
        );
    });

    it('renders a visible badge for a non-actionable job', () => {
        const html = renderToStaticMarkup(
            <FreshnessBadge freshness={view('expired', 'Expired')} />,
        );
        expect(html).toContain('Expired');
        expect(html).toContain('bg-warn-soft');
    });
});
