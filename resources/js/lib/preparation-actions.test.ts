import { describe, expect, it, vi } from 'vitest';
import { postJson, xsrfToken } from './http';
import { cancelPreparation, startPreparation } from './preparation-actions';
import { stateOf } from './preparation-test-data';

const reply = (status: number, data: unknown) =>
    vi.fn(async () => ({ ok: status >= 200 && status < 300, status, data }));

describe('starting a preparation', () => {
    it('sends the chosen resume and returns the attempt the server created', async () => {
        const post = reply(202, {
            created: true,
            preparation: stateOf('queued'),
        });
        const r = await startPreparation(
            '/jobs/3/preparations',
            8,
            post as never,
        );

        expect(post).toHaveBeenCalledWith('/jobs/3/preparations', {
            resume_variant_id: 8,
        });
        expect(r).toMatchObject({ ok: true });
        expect(r.ok && r.state.attempt?.status).toBe('queued');
    });

    it('sends no resume id when none is chosen, leaving the default to the server', async () => {
        const post = reply(202, { preparation: stateOf('queued') });
        await startPreparation('/x', null, post as never);
        expect(post).toHaveBeenCalledWith('/x', {});
    });

    it.each([
        [
            422,
            {
                error: 'This resume renders to 3 pages, over the 2-page limit, so it cannot be used for an application.',
                reason: 'resume_over_length',
            },
            'resume_over_length',
        ],
        [
            422,
            {
                error: 'Applications on this site cannot be prepared yet (only Lever and Greenhouse).',
                reason: 'unsupported_ats_host',
            },
            'unsupported_ats_host',
        ],
        [
            422,
            {
                error: 'This job has no tailored resume yet.',
                reason: 'no_resume',
            },
            'no_resume',
        ],
    ])(
        "shows the server's actionable refusal (%#) and changes nothing",
        async (status, data, code) => {
            const r = await startPreparation(
                '/x',
                7,
                reply(status, data) as never,
            );
            expect(r.ok).toBe(false);
            expect(!r.ok && r.error).toEqual({ code, message: data.error });
        },
    );

    it('explains an expired session and an unreachable server without pretending anything started', async () => {
        const expired = await startPreparation(
            '/x',
            7,
            reply(419, null) as never,
        );
        expect(!expired.ok && expired.error.message).toContain(
            'session expired',
        );
        const offline = await startPreparation(
            '/x',
            7,
            vi.fn(async () => {
                throw new Error('offline');
            }) as never,
        );
        expect(!offline.ok && offline.error.code).toBe('network');
        const odd = await startPreparation('/x', 7, reply(500, null) as never);
        expect(!odd.ok && odd.error.message).toContain('Nothing was changed');
    });
});

describe('cancelling a queued preparation', () => {
    it.each(['cancelled', 'already_cancelled'])(
        'treats "%s" as done',
        async (outcome) => {
            const r = await cancelPreparation(
                '/preparations/11/cancel',
                reply(200, { outcome }) as never,
            );
            expect(r).toEqual({ done: true, error: null });
        },
    );

    it('explains a refusal because a worker got there first, and is not done', async () => {
        const r = await cancelPreparation(
            '/preparations/11/cancel',
            reply(409, {
                outcome: 'not_cancellable',
                current_status: 'running',
            }) as never,
        );
        expect(r.done).toBe(false);
        expect(r.error?.message).toContain('already started');
    });

    it('is not done when the server cannot be reached', async () => {
        const r = await cancelPreparation(
            '/x',
            vi.fn(async () => {
                throw new Error('offline');
            }) as never,
        );
        expect(r).toMatchObject({ done: false, error: { code: 'network' } });
    });
});

describe('the CSRF header for JSON posts', () => {
    it('reads and decodes the XSRF-TOKEN cookie', () => {
        expect(xsrfToken('a=1; XSRF-TOKEN=abc%3D%3D; b=2')).toBe('abc==');
        expect(xsrfToken('XSRF-TOKEN=plain')).toBe('plain');
        expect(xsrfToken('other=1')).toBeNull();
        expect(xsrfToken('')).toBeNull();
        expect(xsrfToken('XSRF-TOKEN=%E0%A4%A')).toBeNull();
    });

    it('sends it with the request, and the body as JSON', async () => {
        const fetchMock = vi.fn(
            async () =>
                new Response(JSON.stringify({ ok: 1 }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const r = await postJson(
            '/jobs/3/preparations',
            { resume_variant_id: 7 },
            'XSRF-TOKEN=tok%2F1',
        );

        const [, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe(
            'tok/1',
        );
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"resume_variant_id":7}');
        expect(r).toEqual({ ok: true, status: 200, data: { ok: 1 } });
        vi.unstubAllGlobals();
    });

    it('returns the status when the body is not JSON', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('<html>', { status: 419 })),
        );
        expect(await postJson('/x', undefined, '')).toEqual({
            ok: false,
            status: 419,
            data: null,
        });
        vi.unstubAllGlobals();
    });
});
