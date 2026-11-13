import { describe, expect, it } from 'vitest';
import { computePopoverPosition, dismissalPayload } from './popover-position';

const viewport = { width: 1000, height: 800 };
const box = { width: 280, height: 200 };

describe('computePopoverPosition', () => {
    it('opens below and right-aligned when there is room', () => {
        const at = computePopoverPosition({ top: 100, bottom: 130, left: 800, right: 830 }, box, viewport);

        expect(at).toEqual({ top: 134, left: 550 });
    });

    it('flips above near the bottom edge', () => {
        const at = computePopoverPosition({ top: 700, bottom: 730, left: 800, right: 830 }, box, viewport);

        expect(at.top).toBe(700 - 4 - 200);
    });

    it('stays inside the viewport on narrow screens', () => {
        const at = computePopoverPosition({ top: 100, bottom: 130, left: 10, right: 40 }, box, { width: 360, height: 800 });

        expect(at.left).toBe(8);
        const right = computePopoverPosition({ top: 100, bottom: 130, left: 330, right: 350 }, box, { width: 360, height: 800 });
        expect(right.left + box.width).toBeLessThanOrEqual(360 - 8);
    });
});

describe('dismissalPayload', () => {
    it('refuses a missing reason', () => {
        expect(dismissalPayload(null, 'x')).toBeNull();
        expect(dismissalPayload('', 'x')).toBeNull();
    });

    it('trims the note', () => {
        expect(dismissalPayload('location', '  too far ')).toEqual({ reason: 'location', note: 'too far' });
    });
});
