import { describe, expect, it } from 'vitest';
import { generateAction } from './tailored-resume-action';

describe('generateAction', () => {
    it('disables the button only while a run is really in progress', () => {
        expect(generateAction({ state: 'running' }, false)).toEqual({
            show: true,
            label: 'Generating…',
            disabled: true,
        });
    });

    it('lets an interrupted run be continued, not left stuck', () => {
        expect(generateAction({ state: 'interrupted' }, false)).toEqual({
            show: true,
            label: 'Continue generating',
            disabled: false,
        });
        expect(generateAction({ state: 'interrupted' }, true).disabled).toBe(
            true,
        );
    });

    it('offers Retry after a real failure and Generate when idle', () => {
        expect(generateAction({ state: 'failed' }, false).label).toBe('Retry');
        expect(generateAction({ state: 'idle' }, false)).toMatchObject({
            show: true,
            label: 'Generate Tailored Resume',
            disabled: false,
        });
    });

    it('hides the button when the resume is ready or questions are waiting', () => {
        expect(generateAction({ state: 'ready' }, false).show).toBe(false);
        expect(
            generateAction({ state: 'needs_clarification' }, false).show,
        ).toBe(false);
    });

    it('never disables the button for a persisted state it cannot call running', () => {
        for (const state of ['idle', 'failed', 'interrupted'] as const) {
            expect(generateAction({ state }, false).disabled).toBe(false);
        }
    });
});
