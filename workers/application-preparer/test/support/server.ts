// A local HTTP server for fixture tests. It serves test/fixtures/* and
// RECORDS every non-GET request, so a test can prove from the server's
// side that nothing was ever submitted.
import { createServer, type Server } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const FIXTURES = join(
    dirname(fileURLToPath(import.meta.url)),
    '..',
    'fixtures',
);

export interface TestServer {
    origin: string;
    /** Every request that was not a GET/HEAD, e.g. "POST /apply". */
    mutations: string[];
    /** Every request path seen (any method): lets a test prove an input event never fired. */
    hits: string[];
    /** Milliseconds to delay the /parseResume response (lets a test observe the loading state). */
    parseDelayMs: number;
    /** HTTP status the /parseResume response uses (default 200). */
    parseStatus: number;
    /** What GET /uncacheable_attributes/presigned_fields returns as `url` (null: the endpoint 404s). */
    presignUrl: string | null;
    /** Number of times the presign endpoint was fetched. */
    presignFetches: number;
    /** Mutations other than the one allowed resume-parse POST. Must be [] in every test. */
    unexpected(): string[];
    /** Serve extra in-memory pages (e.g. a fixture variant) at a path. */
    add(path: string, html: string): void;
    close(): Promise<void>;
}

export async function startServer(): Promise<TestServer> {
    const mutations: string[] = [];
    const hits: string[] = [];
    const state = {
        parseDelayMs: 0,
        parseStatus: 200,
        presignUrl: null as string | null,
        presignFetches: 0,
    };
    const pages = new Map<string, string>();
    const server: Server = createServer(async (req, res) => {
        const url = new URL(req.url ?? '/', 'http://x');
        hits.push(url.pathname);
        if (req.method !== 'GET' && req.method !== 'HEAD') {
            mutations.push(`${req.method} ${url.pathname}`);
            req.resume();
            if (url.pathname === '/parseResume') {
                if (state.parseDelayMs > 0)
                    await new Promise((r) => setTimeout(r, state.parseDelayMs));
                res.writeHead(state.parseStatus, {
                    'content-type': 'application/json',
                }).end('{}');
                return;
            }
            res.writeHead(200, { 'content-type': 'text/html' }).end(
                '<html><body><h1>Thank you for applying</h1></body></html>',
            );
            return;
        }
        if (url.pathname === '/uncacheable_attributes/presigned_fields') {
            state.presignFetches++;
            if (state.presignUrl === null) {
                res.writeHead(404).end('not found');
                return;
            }
            // Same shape as the real endpoint: a destination plus signed fields and a key template.
            const field = {
                fields: {
                    policy: 'synthetic-policy',
                    'x-amz-algorithm': 'SYNTHETIC',
                    'x-amz-signature': 'synthetic-signature',
                },
                key: 'uploads/{timestamp}/{unique_id}/resume',
            };
            res.writeHead(200, { 'content-type': 'application/json' }).end(
                JSON.stringify({
                    url: state.presignUrl,
                    resume: field,
                    cover_letter: field,
                }),
            );
            return;
        }
        const extra = pages.get(url.pathname);
        if (extra !== undefined) {
            res.writeHead(200, {
                'content-type': 'text/html; charset=utf-8',
            }).end(extra);
            return;
        }
        try {
            const body = await readFile(
                join(
                    FIXTURES,
                    url.pathname.replace(/^\/+/, '') || 'index.html',
                ),
            );
            res.writeHead(200, {
                'content-type': 'text/html; charset=utf-8',
            }).end(body);
        } catch {
            res.writeHead(404).end('not found');
        }
    });
    await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
    const address = server.address();
    if (address === null || typeof address === 'string')
        throw new Error('no address');
    return {
        origin: `http://127.0.0.1:${address.port}`,
        mutations,
        hits,
        get parseDelayMs() {
            return state.parseDelayMs;
        },
        set parseDelayMs(v: number) {
            state.parseDelayMs = v;
        },
        get parseStatus() {
            return state.parseStatus;
        },
        set parseStatus(v: number) {
            state.parseStatus = v;
        },
        get presignUrl() {
            return state.presignUrl;
        },
        set presignUrl(v: string | null) {
            state.presignUrl = v;
        },
        get presignFetches() {
            return state.presignFetches;
        },
        add: (path, html) => void pages.set(path, html),
        unexpected: () => mutations.filter((m) => m !== 'POST /parseResume'),
        close: () => new Promise<void>((r) => server.close(() => r())),
    };
}
