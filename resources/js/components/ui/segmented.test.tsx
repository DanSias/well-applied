import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { SegmentedControl } from './segmented';

const render = (items: { value: string; label: string; count?: number }[]) =>
    renderToStaticMarkup(
        <SegmentedControl
            label="View"
            items={items}
            value="inbox"
            onChange={() => {}}
        />,
    );

describe('SegmentedControl counts', () => {
    it('renders each numeric count, including zero, in its own tabular badge after the label', () => {
        const html = render([
            { value: 'inbox', label: 'Inbox', count: 339 },
            { value: 'short', label: 'Shortlist', count: 1 },
            { value: 'final', label: 'Final', count: 0 },
        ]);

        expect(html.match(/data-segmented-count/g)).toHaveLength(3);
        expect(html).toMatch(/Inbox<span[^>]*tabular-nums[^>]*>339<\/span>/);
        expect(html).toMatch(/Shortlist<span[^>]*>1<\/span>/);
        expect(html).toMatch(/Final<span[^>]*>0<\/span>/);
    });

    it('adds no badge to items without a count and keeps aria-pressed state', () => {
        const html = render([
            { value: 'inbox', label: 'Inbox', count: 2 },
            { value: 'other', label: 'Group 1 · 3/5' },
        ]);

        expect(html.match(/data-segmented-count/g)).toHaveLength(1);
        expect(html).toContain('aria-pressed="true"');
        expect(html).toContain('aria-pressed="false"');
        expect(html).toContain('Group 1 · 3/5</button>');
    });

    it('keeps the selected and unselected badges neutral and distinct', () => {
        const html = render([
            { value: 'inbox', label: 'Inbox', count: 5 },
            { value: 'other', label: 'Other', count: 6 },
        ]);

        expect(html).toContain('bg-sunken text-fg-muted');
        expect(html).toContain('bg-fg/10 text-fg-subtle');
        expect(html).not.toMatch(/(red|blue|green|amber|accent)/);
    });
});
