import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { judgeParser } from '../src/parser.ts';

const base = {
    requestAllowed: true,
    waitedMs: 800,
    terminal: true,
    attached: true,
} as const;

describe('judgeParser: success only from a visible indicator', () => {
    it('visible success is success', () => {
        assert.equal(
            judgeParser({ ...base, observation: 'success' }).status,
            'success',
        );
    });
    it('visible failure is failure', () => {
        assert.equal(
            judgeParser({ ...base, observation: 'failure' }).status,
            'failure',
        );
    });
    it('an allowed request with NO visible indicator is unknown, never success', () => {
        const r = judgeParser({
            ...base,
            observation: 'none',
            terminal: false,
        });
        assert.equal(r.status, 'unknown');
        assert.match(r.detail, /no parser indicator became visible/);
    });
    it('still "working" when the wait ran out is unknown, with the time stated', () => {
        const r = judgeParser({
            ...base,
            observation: 'working',
            terminal: false,
            waitedMs: 15000,
        });
        assert.equal(r.status, 'unknown');
        assert.match(r.detail, /still analyzing after 15000ms/);
    });
    it('conflicting indicators claim neither', () => {
        assert.equal(
            judgeParser({ ...base, observation: 'conflict' }).status,
            'unknown',
        );
    });
    it('no resume attached is not_attempted, whatever the page shows', () => {
        assert.equal(
            judgeParser({ ...base, observation: 'success', attached: false })
                .status,
            'not_attempted',
        );
    });
    it('no allowed request and no indicator is not_attempted', () => {
        assert.equal(
            judgeParser({
                ...base,
                requestAllowed: false,
                observation: 'none',
                terminal: false,
            }).status,
            'not_attempted',
        );
    });
    it('a visible failure is reported as failure even when the request was never allowed (the guard refused it)', () => {
        assert.equal(
            judgeParser({
                ...base,
                requestAllowed: false,
                observation: 'failure',
            }).status,
            'failure',
        );
    });
});
