import { describe, expect, it } from 'vitest';
import {
    failureExplanation,
    isActiveState,
    phaseLabel,
    presentAttempt,
    presentPreparation,
    uploadExplanation,
} from './preparation-status';
import { attemptRow, stateOf } from './preparation-test-data';

describe('presentPreparation: execution and outcome stay apart', () => {
    it('is not started with no attempt, and offers the first start', () => {
        const p = presentPreparation(null);
        expect(p.kind).toBe('none');
        expect(p.headline).toEqual({ label: 'Not started', tone: 'neutral' });
        expect(p.active).toBe(false);
        expect(p.startLabel).toBe('Prepare application');
    });

    it('is queued: active, cancellable, and honest about waiting', () => {
        const p = presentPreparation(stateOf('queued'));
        expect(p).toMatchObject({
            kind: 'queued',
            active: true,
            canCancel: true,
            headline: { label: 'Queued' },
        });
        expect(p.explanation).toContain('cancel');
    });

    it('is running: active, with its real phase, and not cancellable', () => {
        const p = presentPreparation(
            stateOf('running', { phase: 'uploading' }),
        );
        expect(p).toMatchObject({
            kind: 'running',
            active: true,
            canCancel: false,
        });
        expect(p.explanation).toContain('Attaching your resume');
        expect(p.explanation).toContain('cannot be cancelled');
    });

    it('never shows a cancelled attempt as queued, and allows a new one', () => {
        const p = presentPreparation(stateOf('cancelled'));
        expect(p).toMatchObject({
            kind: 'cancelled',
            active: false,
            canCancel: false,
            headline: { label: 'Cancelled' },
            startLabel: 'Start a new attempt',
        });
        expect(p.headline.label).not.toBe('Queued');
    });

    it('is a failed execution with a reason, never automatically retried', () => {
        const p = presentPreparation(
            stateOf('failed', { failure: 'claim_timeout' }),
        );
        expect(p).toMatchObject({
            kind: 'failed',
            active: false,
            headline: { label: 'Failed', tone: 'bad' },
            startLabel: 'Start a new attempt',
        });
        expect(p.explanation).toContain('not retried automatically');
        expect(p.explanation).toContain('Nothing was submitted');
    });

    it.each([
        ['ready_for_review', 'ready', 'Ready for review', 'ok'],
        ['input_required', 'input', 'Needs your input', 'warn'],
        ['blocked', 'blocked', 'Blocked', 'warn'],
        ['unsupported', 'unsupported', 'Not supported', 'warn'],
        ['safety_failure', 'safety', 'Stopped for safety', 'bad'],
    ] as const)(
        'maps the delivered outcome %s without calling the run a failure',
        (outcome, kind, label, tone) => {
            const p = presentPreparation(stateOf('succeeded', { outcome }));
            expect(p.kind).toBe(kind);
            expect(p.headline).toEqual({ label, tone });
            expect(p.active).toBe(false);
            expect(p.headline.label).not.toBe('Failed');
        },
    );

    it('treats a delivered input_required report as a successful run that still needs the person', () => {
        const p = presentPreparation(
            stateOf('succeeded', { outcome: 'input_required' }),
        );
        expect(p.hasReview).toBe(true);
        expect(p.explanation).toContain('still need an answer');
        expect(p.explanation).toContain('not been submitted');
    });

    it('never presents a safety stop as ready, and gives it no new start label of "ready"', () => {
        const p = presentPreparation(
            stateOf('succeeded', {
                outcome: 'safety_failure',
                reviewable: false,
            }),
        );
        expect(p.kind).toBe('safety');
        expect(p.headline.tone).toBe('bad');
        expect(p.headline.label).not.toMatch(/ready/i);
    });

    it('flags a report whose evidence does not hold together instead of presenting it as prepared', () => {
        const p = presentPreparation(
            stateOf('succeeded', {
                outcome: 'ready_for_review',
                reviewable: false,
            }),
        );
        expect(p.kind).toBe('check');
        expect(p.headline).toEqual({
            label: 'Check the evidence',
            tone: 'warn',
        });
    });

    it("reads an outcome only from its own attempt, never an older attempt's result", () => {
        const state = stateOf('succeeded', {
            id: 2,
            outcome: 'ready_for_review',
        });
        state.latest_result = { ...state.latest_result!, agent_run_id: 1 };
        expect(presentPreparation(state).kind).toBe('check');
        expect(presentPreparation(state).headline.label).toBe('Finished');
    });

    it('never says submitted, sent or applied in any label or explanation', () => {
        const states = [
            stateOf('queued'),
            stateOf('running'),
            stateOf('cancelled'),
            stateOf('failed', { failure: 'unexpected_error' }),
            ...(
                [
                    'ready_for_review',
                    'input_required',
                    'blocked',
                    'unsupported',
                    'safety_failure',
                ] as const
            ).map((o) => stateOf('succeeded', { outcome: o })),
        ];
        for (const s of states) {
            const p = presentPreparation(s);
            expect(p.headline.label).not.toMatch(
                /submitted|sent|applied|complete/i,
            );
            expect(p.explanation).not.toMatch(
                /(?<!not been |never |Nothing was |not )submitted\./i,
            );
        }
    });
});

describe('isActiveState', () => {
    it('is active only while queued or running', () => {
        expect(isActiveState(null)).toBe(false);
        expect(isActiveState(stateOf('queued'))).toBe(true);
        expect(isActiveState(stateOf('running'))).toBe(true);
        for (const s of ['succeeded', 'failed', 'cancelled'] as const)
            expect(isActiveState(stateOf(s))).toBe(false);
    });
});

describe('presentAttempt: each attempt on its own', () => {
    it('labels a history row from its own status and outcome', () => {
        expect(
            presentAttempt(attemptRow({ status: 'cancelled', outcome: null }))
                .label,
        ).toBe('Cancelled');
        expect(
            presentAttempt(attemptRow({ status: 'failed', outcome: null }))
                .label,
        ).toBe('Failed');
        expect(
            presentAttempt(attemptRow({ outcome: 'ready_for_review' })).label,
        ).toBe('Ready for review');
        expect(
            presentAttempt(
                attemptRow({ outcome: 'safety_failure', reviewable: false }),
            ).label,
        ).toBe('Stopped for safety');
        expect(
            presentAttempt(
                attemptRow({ outcome: 'ready_for_review', reviewable: false }),
            ).label,
        ).toBe('Check the evidence');
        expect(presentAttempt(attemptRow({ outcome: null })).label).toBe(
            'Finished',
        );
    });
});

describe('wording helpers', () => {
    it('gives every phase and every known failure a plain sentence, and a safe fallback', () => {
        expect(phaseLabel(null)).toBe('Working on the form');
        expect(phaseLabel('opening')).toContain('Opening');
        for (const c of [
            'claim_timeout',
            'navigation_timeout',
            'unsupported_ats',
            'unexpected_error',
        ])
            expect(failureExplanation(c)).toContain('Nothing was submitted');
        expect(failureExplanation('something_new')).toContain(
            'Nothing was submitted',
        );
        expect(failureExplanation(null)).toContain('not retried automatically');
    });

    it('explains the upload evidence in words, and never claims acceptance without it', () => {
        expect(uploadExplanation('accepted_filename_visible')).toContain(
            'accepted the upload',
        );
        expect(uploadExplanation('accepted_confirmation_missing')).toContain(
            'not verified',
        );
        expect(uploadExplanation('failed')).toContain('not verified');
        expect(uploadExplanation(undefined)).toContain('No upload evidence');
    });
});
