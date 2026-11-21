import { readFileSync } from 'node:fs';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import type { JobFreshnessView } from '@/types/job-posting';
import {
    newWorkBlock,
    FreshnessBadge,
    FreshnessNote,
    freshnessBadgeLabel,
    freshnessNote,
} from './job-freshness';

const view = (over: Partial<JobFreshnessView>): JobFreshnessView => ({
    state: 'unchecked',
    label: 'Not live-checked',
    actionable: true,
    reason: 'no_observation',
    tone: 'neutral',
    detail: 'No live check with the provider has been made, so this job is open only as far as our records go.',
    new_work_block: null,
    confirmed_at: null,
    checked: false,
    ...over,
});

const unchecked = view({});
const confirmed = view({
    state: 'active',
    label: 'Confirmed open',
    reason: 'provider_reports_open',
    tone: 'ok',
    detail: 'The provider confirmed this job open.',
    confirmed_at: '2026-11-22T09:30:00+00:00',
    checked: true,
});
const possiblyClosed = view({
    state: 'uncertain',
    label: 'May be closed',
    reason: 'closed_once_awaiting_confirmation',
    tone: 'warn',
    detail: 'The provider reported this job closed once; it has not been confirmed.',
    checked: true,
});
const inconclusive = view({
    state: 'uncertain',
    label: 'Check inconclusive',
    reason: 'only_uncertain_observations',
    tone: 'neutral',
    detail: 'The last check with the provider gave no clear answer. This is not a sign the job is closed.',
    checked: true,
});
const expired = view({
    state: 'expired',
    label: 'Expired',
    actionable: false,
    reason: 'expired_known',
    tone: 'warn',
    detail: 'The provider gave this job an expiry date, and it has passed.',
    new_work_block:
        'This job has expired, so new pursuit work cannot be started on it.',
    checked: true,
});
const closed = view({
    state: 'closed',
    label: 'Closed',
    actionable: false,
    reason: 'closure_confirmed',
    tone: 'warn',
    detail: 'The provider reported this job closed on repeated checks.',
    new_work_block:
        'This job is closed, so new pursuit work cannot be started on it.',
    checked: true,
});

describe('freshness badge (lists and headers)', () => {
    it('badges a provider-confirmed open job and every warning state, and never a neutral one', () => {
        expect(freshnessBadgeLabel(confirmed)).toBe('Confirmed open');
        expect(freshnessBadgeLabel(expired)).toBe('Expired');
        expect(freshnessBadgeLabel(closed)).toBe('Closed');
        expect(freshnessBadgeLabel(possiblyClosed)).toBe('May be closed');
        expect(freshnessBadgeLabel(unchecked)).toBeNull();
        expect(freshnessBadgeLabel(inconclusive)).toBeNull();
    });

    it('shows nothing when the feature is off (null), so the page is exactly as before', () => {
        expect(freshnessBadgeLabel(null)).toBeNull();
        expect(freshnessBadgeLabel(undefined)).toBeNull();
        expect(renderToStaticMarkup(<FreshnessBadge freshness={null} />)).toBe(
            '',
        );
        expect(renderToStaticMarkup(<FreshnessNote freshness={null} />)).toBe(
            '',
        );
    });

    it('gives confirmed-open an ok tone and the warning states the warn tone', () => {
        expect(
            renderToStaticMarkup(<FreshnessBadge freshness={confirmed} />),
        ).toContain('bg-ok-soft');
        for (const warn of [expired, closed, possiblyClosed]) {
            const html = renderToStaticMarkup(
                <FreshnessBadge freshness={warn} />,
            );
            expect(html).toContain('bg-warn-soft');
            expect(html).not.toContain('bg-ok-soft');
        }
    });

    it('renders nothing for an unchecked or inconclusive job: no badge, no warning styling', () => {
        for (const quiet of [unchecked, inconclusive]) {
            const html = renderToStaticMarkup(
                <FreshnessBadge freshness={quiet} />,
            );
            expect(html).toBe('');
        }
    });
});

describe('freshness note (drawers and workspaces)', () => {
    it('says explicitly that an unchecked job is open only as far as our records go, in a muted style', () => {
        const html = renderToStaticMarkup(
            <FreshnessNote freshness={unchecked} />,
        );
        expect(html).toContain('Not live-checked');
        expect(html).toContain('only as far as our records go');
        expect(html).toContain('text-fg-subtle');
        expect(html).not.toContain('text-warn');
        expect(html).not.toContain('Confirmed open');
    });

    it('says a confirmed job was confirmed BY THE PROVIDER, with the day, and is never worded like an unchecked one', () => {
        const text = freshnessNote(confirmed);
        expect(text).toBe(
            'The provider confirmed this job open on 2026-11-22.',
        );
        expect(freshnessNote(unchecked)).not.toContain('confirmed');
    });

    it('says an inconclusive check is not a sign of closure, without warning styling', () => {
        const html = renderToStaticMarkup(
            <FreshnessNote freshness={inconclusive} />,
        );
        expect(html).toContain('not a sign the job is closed');
        expect(html).toContain('text-fg-subtle');
    });

    it('uses the warning style only for expired, closed and possibly closed', () => {
        for (const warn of [expired, closed, possiblyClosed]) {
            expect(
                renderToStaticMarkup(<FreshnessNote freshness={warn} />),
            ).toContain('text-warn');
        }
        for (const quiet of [unchecked, confirmed, inconclusive]) {
            expect(
                renderToStaticMarkup(<FreshnessNote freshness={quiet} />),
            ).not.toContain('text-warn');
        }
    });
});

describe('the freshness UI is display only', () => {
    const source = (path: string) => readFileSync(path, 'utf8');

    it('has no request, mutation, polling or inspection call in the component', () => {
        expect(source('resources/js/components/job-freshness.tsx')).not.toMatch(
            /router\.|fetch\(|axios|useForm|usePoll|XMLHttpRequest|useStartInspection|startInspection|useEffect/,
        );
    });

    it('makes the "show them" toggle a GET navigation and nothing else', () => {
        const index = source('resources/js/pages/jobs/index.tsx');
        const start = index.indexOf('const toggleInactive');
        const toggle = index.slice(
            start,
            index.indexOf('const applyFilters', start),
        );
        expect(toggle).toContain('router.get(');
        expect(toggle).not.toMatch(
            /router\.(post|put|patch|delete)|fetch\(|generate|dispatch|startInspection/,
        );
    });
});

describe('restrained list labels and no repeated wording', () => {
    it('badges only the warning states on list rows (warnOnly): never a confirmed-open or neutral one', () => {
        expect(freshnessBadgeLabel(expired, true)).toBe('Expired');
        expect(freshnessBadgeLabel(closed, true)).toBe('Closed');
        expect(freshnessBadgeLabel(possiblyClosed, true)).toBe('May be closed');
        expect(freshnessBadgeLabel(confirmed, true)).toBeNull();
        expect(freshnessBadgeLabel(unchecked, true)).toBeNull();
        expect(freshnessBadgeLabel(inconclusive, true)).toBeNull();
        expect(
            renderToStaticMarkup(
                <FreshnessBadge freshness={unchecked} warnOnly />,
            ),
        ).toBe('');
        expect(
            renderToStaticMarkup(
                <FreshnessBadge freshness={confirmed} warnOnly />,
            ),
        ).toBe('');
        expect(
            renderToStaticMarkup(
                <FreshnessBadge freshness={expired} warnOnly />,
            ),
        ).toContain('Expired');
    });

    it('does not repeat the badge label in the note; only the unbadged neutral states lead with it', () => {
        for (const badged of [expired, closed, possiblyClosed, confirmed]) {
            expect(freshnessNote(badged)).not.toContain(badged.label);
        }
        expect(freshnessNote(expired)).toBe(
            'The provider gave this job an expiry date, and it has passed.',
        );
        expect(freshnessNote(unchecked)).toMatch(/^Not live-checked: /);
        expect(freshnessNote(inconclusive)).toMatch(/^Check inconclusive: /);
    });

    it('takes the reason new work is blocked from the server decision: only expired and closed block', () => {
        expect(newWorkBlock(expired)).toContain('expired');
        expect(newWorkBlock(closed)).toContain('closed');
        for (const open of [
            unchecked,
            confirmed,
            possiblyClosed,
            inconclusive,
            null,
            undefined,
        ]) {
            expect(newWorkBlock(open)).toBeNull();
        }
    });
});
