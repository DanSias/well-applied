import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() } }));
vi.mock('@/routes/opportunities', () => ({
    stop: { url: () => '/opportunities/1/stop' },
}));

import { STOP_REASONS, StopPursuingPopover } from './stop-pursuing-popover';

describe('StopPursuingPopover', () => {
    const html = renderToStaticMarkup(
        <StopPursuingPopover jobId={1} company="Deepgram" />,
    );

    it('renders only a quiet trigger until opened, announcing the popup', () => {
        expect(html).toContain('Stop pursuing');
        expect(html).toContain('aria-haspopup="dialog"');
        expect(html).toContain('aria-expanded="false"');
        expect(html).not.toContain('type="radio"');
    });

    it('offers the four reasons, none preselected', () => {
        expect(STOP_REASONS.map((r) => r.label)).toEqual([
            'Applications closed',
            'No longer interested',
            'Not a good fit',
            'Other reason',
        ]);
    });
});
