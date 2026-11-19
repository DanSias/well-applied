import { beforeEach, describe, expect, it, vi } from 'vitest';

const { toastFn, post, on } = vi.hoisted(() => ({
    toastFn: {
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
    post: vi.fn(),
    on: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: toastFn }));
vi.mock('@inertiajs/react', () => ({ router: { post, on } }));
vi.mock('@/routes/jobs/decisions', () => ({
    undo: { url: () => '/jobs/decisions/undo' },
}));

import { batchToast } from './batch-toast';
import { decisionToast } from './decision-toast';
import { notify, TOAST_DURATION_MS } from './toast';
import type { BatchResult, DecisionResult } from '@/types/job-posting';

const decision = (over: Partial<DecisionResult>): DecisionResult => ({
    to: 'shortlisted',
    changed: [],
    unchanged: [],
    failed: [],
    not_found: [],
    decision_ids: {},
    ...over,
});

const batch = (over: Partial<BatchResult>): BatchResult => ({
    operation: 'analyze',
    requested: 0,
    started: 0,
    skipped: 0,
    failed: 0,
    not_found: 0,
    items: [],
    ...over,
});

// flash-toasts keeps a module-level "undo in flight" flag, so each test gets a fresh copy of it.
let showFlash: typeof import('./flash-toasts').showFlash;
let registerFlashToasts: typeof import('./flash-toasts').registerFlashToasts;

beforeEach(async () => {
    vi.clearAllMocks();
    vi.resetModules();
    ({ showFlash, registerFlashToasts } = await import('./flash-toasts'));
});

describe('how long a notification stays', () => {
    it('keeps success and info to about four seconds and errors longer', () => {
        expect(TOAST_DURATION_MS.success).toBe(4000);
        expect(TOAST_DURATION_MS.info).toBe(4000);
        expect(TOAST_DURATION_MS.warning).toBeGreaterThan(4000);
        expect(TOAST_DURATION_MS.error).toBeGreaterThanOrEqual(6000);
        expect(TOAST_DURATION_MS.error).toBeLessThanOrEqual(8000);
    });

    it('passes each kind its own duration, with the title, description and action', () => {
        const action = { label: 'Undo', onClick: () => {} };

        notify.success('Saved', { description: 'ok', action });
        notify.error('Nope');

        expect(toastFn.success).toHaveBeenCalledWith('Saved', {
            description: 'ok',
            action,
            id: undefined,
            duration: 4000,
        });
        expect(toastFn.error).toHaveBeenCalledWith(
            'Nope',
            expect.objectContaining({ duration: 8000 }),
        );
    });
});

describe('the words after a job decision', () => {
    it('names the company when pursuit was stopped, with Undo, and only once the server changed it', () => {
        const ids = { '5': 11 };
        const stopped = { company: 'Deepgram', title: 'Engineer' };

        expect(
            decisionToast(
                decision({
                    to: 'dismissed',
                    changed: [5],
                    decision_ids: ids,
                    stopped_pursuing: stopped,
                }),
            ),
        ).toMatchObject({
            kind: 'success',
            title: 'Stopped pursuing Deepgram',
            undo: ids,
        });
        expect(
            decisionToast(
                decision({
                    to: 'dismissed',
                    failed: [{ id: 5, message: 'No longer pursued' }],
                    stopped_pursuing: stopped,
                }),
            ),
        ).toMatchObject({
            kind: 'error',
            title: "Couldn't stop pursuing that opportunity",
        });
    });

    it('says what happened to one job, and offers Undo', () => {
        const ids = { '5': 11 };

        expect(
            decisionToast(
                decision({
                    to: 'shortlisted',
                    changed: [5],
                    decision_ids: ids,
                }),
            ),
        ).toEqual({
            kind: 'success',
            title: 'Job shortlisted',
            description: undefined,
            undo: ids,
        });
        expect(
            decisionToast(
                decision({ to: 'dismissed', changed: [5], decision_ids: ids }),
            ).title,
        ).toBe('Job dismissed');
        expect(
            decisionToast(
                decision({ to: 'pursued', changed: [5], decision_ids: ids }),
            ).title,
        ).toBe('Job added to Opportunities');
        expect(
            decisionToast(
                decision({ to: 'new', changed: [5], decision_ids: ids }),
            ).title,
        ).toBe('Job returned to Inbox');
    });

    it('counts a bulk decision, and keeps Undo for all of it', () => {
        const ids = { '1': 1, '2': 2, '3': 3 };

        expect(
            decisionToast(decision({ changed: [1, 2, 3], decision_ids: ids })),
        ).toMatchObject({
            kind: 'success',
            title: '3 jobs shortlisted',
            undo: ids,
        });
        expect(
            decisionToast(
                decision({ to: 'new', changed: [1, 2], decision_ids: ids }),
            ).title,
        ).toBe('2 jobs returned to Inbox');
    });

    it('confirms an undo, with nothing further to undo', () => {
        expect(
            decisionToast(
                decision({
                    to: 'undo',
                    changed: [1],
                    decision_ids: { '1': 9 },
                }),
            ),
        ).toEqual({
            kind: 'success',
            title: 'Decision undone',
            description: undefined,
            undo: undefined,
        });
        expect(
            decisionToast(decision({ to: 'undo', changed: [1, 2] })).title,
        ).toBe('2 decisions undone');
    });

    it('reports a partial failure accurately: a warning that names what was refused and what was already so', () => {
        const toast = decisionToast(
            decision({
                changed: [1, 2],
                unchanged: [3],
                failed: [
                    {
                        id: 4,
                        message:
                            'A dismissed job cannot go straight to pursued.',
                    },
                    {
                        id: 5,
                        message:
                            'A dismissed job cannot go straight to pursued.',
                    },
                ],
                not_found: [6],
                decision_ids: { '1': 1, '2': 2 },
            }),
        );

        expect(toast.kind).toBe('warning');
        expect(toast.title).toBe('2 jobs shortlisted');
        expect(toast.description).toBe(
            '1 already was there · 1 job not found · 2 jobs: A dismissed job cannot go straight to pursued.',
        );
        expect(toast.undo).toEqual({ '1': 1, '2': 2 });
    });

    it('is an error with no Undo when nothing could be changed, and info when everything was already there', () => {
        expect(
            decisionToast(
                decision({
                    failed: [
                        { id: 1, message: 'Only a person can pursue a job.' },
                    ],
                }),
            ),
        ).toEqual({
            kind: 'error',
            title: "Couldn't update that job",
            description: 'Only a person can pursue a job.',
        });
        expect(decisionToast(decision({ unchanged: [1, 2] }))).toMatchObject({
            kind: 'info',
            title: 'Nothing to change',
            description: '2 already were there',
        });
    });

    it('explains a failed undo instead of staying silent', () => {
        const toast = decisionToast(
            decision({
                to: 'undo',
                failed: [
                    {
                        id: 1,
                        message:
                            'This job has changed since, so that decision can no longer be undone.',
                    },
                ],
            }),
        );

        expect(toast).toEqual({
            kind: 'error',
            title: "Couldn't undo that decision",
            description:
                'This job has changed since, so that decision can no longer be undone.',
        });
    });
});

describe('the words after an analyze or match batch', () => {
    it('reports started jobs, and why others were not started', () => {
        expect(batchToast(batch({ started: 3, requested: 3 }))).toEqual({
            kind: 'success',
            title: 'Analysis started for 3 jobs',
            description: undefined,
        });
        expect(
            batchToast(
                batch({
                    operation: 'match',
                    started: 1,
                    skipped: 2,
                    items: [
                        { job_id: 1, outcome: 'skipped', reason: 'in_flight' },
                        { job_id: 2, outcome: 'skipped', reason: 'in_flight' },
                    ],
                }),
            ),
        ).toEqual({
            kind: 'info',
            title: 'Matching started for 1 job',
            description: '2 skipped · 2 already in flight',
        });
        expect(batchToast(batch({ started: 2, failed: 1 })).kind).toBe(
            'warning',
        );
        expect(batchToast(batch({ failed: 2 }))).toMatchObject({
            kind: 'error',
            title: 'Analysis not started',
        });
    });
});

describe('flash becoming a toast', () => {
    it('shows a decision with an Undo that posts the recorded decision ids, once at a time', () => {
        showFlash({
            decision_result: decision({
                changed: [7],
                decision_ids: { '7': 21 },
            }),
        });

        const [title, options] = toastFn.success.mock.calls[0];
        expect(title).toBe('Job shortlisted');
        expect(options.action.label).toBe('Undo');

        options.action.onClick();
        options.action.onClick();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith(
            '/jobs/decisions/undo',
            { decisions: { '7': 21 } },
            expect.any(Object),
        );

        // The request finishing frees the next Undo.
        post.mock.calls[0][2].onFinish();
        options.action.onClick();
        expect(post).toHaveBeenCalledTimes(2);
    });

    it('tells the person when an Undo request is rejected', () => {
        showFlash({
            decision_result: decision({
                changed: [7],
                decision_ids: { '7': 21 },
            }),
        });
        toastFn.success.mock.calls[0][1].action.onClick();

        post.mock.calls[0][2].onError({ decisions: 'Bad decisions' });

        expect(toastFn.error).toHaveBeenCalledWith(
            "Couldn't undo that decision",
            expect.objectContaining({ description: 'Bad decisions' }),
        );
    });

    it('shows nothing for flash it does not know, and a batch result as a toast without Undo', () => {
        showFlash({});
        expect(
            Object.values(toastFn).every((fn) => fn.mock.calls.length === 0),
        ).toBe(true);

        showFlash({ batch_result: batch({ started: 2 }) });
        expect(toastFn.success.mock.calls[0][1].action).toBeUndefined();
    });

    it('listens to the app-wide flash event once and can stop', () => {
        const off = vi.fn();
        on.mockReturnValue(off);

        const stop = registerFlashToasts();
        on.mock.calls[0][1]({
            detail: {
                flash: {
                    decision_result: decision({
                        changed: [1],
                        decision_ids: { '1': 2 },
                    }),
                },
            },
        });

        expect(on).toHaveBeenCalledWith('flash', expect.any(Function));
        expect(toastFn.success).toHaveBeenCalledTimes(1);
        expect(stop).toBe(off);
    });
});
