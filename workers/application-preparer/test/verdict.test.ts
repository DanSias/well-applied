import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { judgeText, worse, type ControlState } from '../src/verdict.ts';

const state = (over: Partial<ControlState> = {}): ControlState => ({
    visible: true,
    visibleValue: 'Springfield',
    composite: false,
    signals: [],
    hiddenValues: [],
    ...over,
});

describe('judgeText: the visible control is the evidence', () => {
    it('ordinary input: visible and equal is verified (straightforward)', () => {
        assert.deepEqual(judgeText('Springfield', state()), {
            status: 'filled_verified',
        });
    });

    it('ordinary input: unrelated hidden values are not consulted', () => {
        assert.equal(
            judgeText('Springfield', state({ hiddenValues: [] })).status,
            'filled_verified',
        );
    });

    it('a displayed value that differs is an error', () => {
        assert.equal(
            judgeText('Springfield', state({ visibleValue: '' })).status,
            'error',
        );
        assert.equal(
            judgeText('Springfield', state({ visibleValue: 'Spring' })).status,
            'error',
        );
    });

    it('a hidden input holding the value never rescues an empty or different display', () => {
        const v = judgeText(
            'Springfield',
            state({
                visibleValue: '',
                composite: true,
                signals: ['related_hidden_input'],
                hiddenValues: ['Springfield'],
            }),
        );
        assert.equal(v.status, 'error');
        assert.match(v.note!, /displayed value wins/);
    });

    it('a control that is not visible is unverified even if its value matches', () => {
        const v = judgeText('Springfield', state({ visible: false }));
        assert.equal(v.status, 'unverified');
        assert.match(v.note!, /not visible/);
    });

    it('composite control with displayed text but no confirmed underlying value is unverified', () => {
        const v = judgeText(
            'Springfield',
            state({ composite: true, signals: ['role_combobox'] }),
        );
        assert.equal(v.status, 'unverified');
        assert.match(v.note!, /no committed value could be confirmed/);
    });

    it('composite control whose hidden value disagrees is unverified', () => {
        const v = judgeText(
            'Springfield',
            state({
                composite: true,
                signals: ['related_hidden_input'],
                hiddenValues: [''],
            }),
        );
        assert.equal(v.status, 'unverified');
        assert.match(v.note!, /does not match/);
    });

    it('composite control is verified only when displayed text AND underlying value agree', () => {
        assert.equal(
            judgeText(
                'Springfield',
                state({
                    composite: true,
                    signals: ['related_hidden_input'],
                    hiddenValues: ['Springfield'],
                }),
            ).status,
            'filled_verified',
        );
    });

    it('worse() ranks error > unverified > verified', () => {
        const ok = { status: 'filled_verified' } as const;
        const un = { status: 'unverified', note: 'x' } as const;
        const er = { status: 'error', note: 'y' } as const;
        assert.equal(worse(ok, un), un);
        assert.equal(worse(un, er), er);
        assert.equal(worse(er, ok), er);
        assert.equal(worse(ok, ok), ok);
    });
});
