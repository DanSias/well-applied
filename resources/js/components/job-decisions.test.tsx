import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { CompactRowActions, DecisionActions } from './job-decisions';

// The popover and the overflow menu open portals (they need a DOM); only the buttons under test matter here.
vi.mock('@/components/dismiss-popover', () => ({
    DismissPopover: () => <span>Dismiss</span>,
}));
vi.mock('@/components/overflow-menu', () => ({
    OverflowMenu: () => null,
}));

const BLOCK =
    'This job has expired, so new pursuit work cannot be started on it.';

const DISABLED = / disabled=""/;

/** The opening tag of the button whose aria-label starts with `label`. */
const button = (html: string, label: string) => {
    const match = html.match(
        new RegExp(`<button[^>]*aria-label="${label}[^"]*"[^>]*>`),
    );

    return match === null ? null : match[0];
};

describe('shortlist and pursue on an expired or closed job', () => {
    for (const disposition of ['new', 'shortlisted'] as const) {
        it(`are disabled, with the reason, in the ${disposition} drawer actions; dismiss and restore stay`, () => {
            const html = renderToStaticMarkup(
                <DecisionActions
                    disposition={disposition}
                    jobLabel="Eng at Acme"
                    disabled={false}
                    onDecide={vi.fn()}
                    blocked={BLOCK}
                />,
            );

            expect(button(html, 'Pursue')).toMatch(DISABLED);
            expect(button(html, 'Pursue')).toContain(BLOCK);
            if (disposition === 'new') {
                expect(button(html, 'Shortlist')).toMatch(DISABLED);
            }
            expect(html).toContain('Dismiss');
            if (disposition === 'shortlisted') {
                expect(button(html, 'Restore')).not.toMatch(DISABLED);
            }
        });

        it(`are disabled in the ${disposition} list row; the dismiss control stays`, () => {
            const html = renderToStaticMarkup(
                <CompactRowActions
                    disposition={disposition}
                    jobLabel="Eng at Acme"
                    disabled={false}
                    onDecide={vi.fn()}
                    extraItems={[]}
                    blocked={BLOCK}
                />,
            );

            expect(button(html, 'Pursue')).toMatch(DISABLED);
            expect(html).toMatch(/Dismiss/);
        });
    }

    it('are enabled, with no title, when nothing blocks them (unchecked, confirmed open, possibly closed, or the feature off)', () => {
        const drawer = renderToStaticMarkup(
            <DecisionActions
                disposition="new"
                jobLabel="Eng at Acme"
                disabled={false}
                onDecide={vi.fn()}
                blocked={null}
            />,
        );
        const row = renderToStaticMarkup(
            <CompactRowActions
                disposition="new"
                jobLabel="Eng at Acme"
                disabled={false}
                onDecide={vi.fn()}
                extraItems={[]}
            />,
        );

        for (const html of [drawer, row]) {
            expect(button(html, 'Shortlist')).not.toMatch(DISABLED);
            expect(button(html, 'Pursue')).not.toMatch(DISABLED);
            expect(button(html, 'Pursue')).not.toContain('title=');
        }
    });

    it('still disables everything while a decision is in flight', () => {
        const html = renderToStaticMarkup(
            <DecisionActions
                disposition="new"
                jobLabel="Eng at Acme"
                disabled
                onDecide={vi.fn()}
            />,
        );

        expect(button(html, 'Pursue')).toMatch(DISABLED);
    });
});
