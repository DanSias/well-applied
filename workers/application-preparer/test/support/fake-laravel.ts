// An in-memory stand-in for Well Applied's preparer protocol, faithful to the rules the real Laravel backend enforces
// (docs/application-preparation.md): token + X-Worker-Identity scoping, a renewable lease with failed-never-rerun
// recovery, write-once PNG screenshots, validated and idempotent results, and 409 for anything stale. It also injects
// the failures the agent must survive (dropped connections, heartbeat rejection, a tampered resume).
//
// It records everything a test needs to prove: what was uploaded, what results arrived, and that nothing else did.
import { createHash } from 'node:crypto';
import {
    createServer,
    type IncomingMessage,
    type Server,
    type ServerResponse,
} from 'node:http';

export const TOKEN = 'test-preparer-token';

const SHOT = /^[a-z][a-z_]{0,19}-\d{2}of\d{2}-y\d{5}\.png$/;
const PNG = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
const sha = (b: Buffer) => createHash('sha256').update(b).digest('hex');

export interface FakeRun {
    id: number;
    status: 'queued' | 'running' | 'succeeded' | 'failed';
    identity: string | null;
    leaseUntil: number;
    failure: string | null;
    applicationUrl: string;
    applicant: Record<string, string>;
    resume: Buffer;
    filename: string;
    screenshots: Map<string, Buffer>;
    results: unknown[];
    heartbeats: number;
    accepted: unknown | null;
    /** Everything after the lease was lost that arrived anyway (must stay empty). */
    afterLoss: string[];
}

export interface FakeOptions {
    leaseSeconds: number;
}

export interface FakeLaravel {
    origin: string;
    /** Lease length handed out at claim and on each heartbeat; a test may shorten it. */
    leaseSeconds: number;
    runs: Map<number, FakeRun>;
    /** Every request, as "METHOD path status", in order. */
    log: string[];
    enqueue(o: {
        applicationUrl: string;
        applicant?: Record<string, string>;
        resume?: Buffer;
        filename?: string;
    }): FakeRun;
    /** Serve a different resume than the one pinned (a tampered download). */
    tamperResume: boolean;
    /** Refuse every heartbeat with 409, as Laravel does for a lapsed claim. */
    rejectHeartbeats: boolean;
    /** Drop the connection for every heartbeat (a network outage). */
    dropHeartbeats: boolean;
    /** Result requests to answer by destroying the socket. */
    dropResultsBefore: number;
    /** ...or after Laravel has processed them (the response is lost, not the request). */
    dropResultsAfter: number;
    /** Make every result a 422. */
    rejectResults: boolean;
    /** Drop every connection for result requests, forever. */
    blackholeResults: boolean;
    /** Lapse a run's lease now, as time passing would. */
    lapse(id: number): void;
    close(): Promise<void>;
}

const body = (req: IncomingMessage): Promise<Buffer> =>
    new Promise((resolve) => {
        const chunks: Buffer[] = [];
        req.on('data', (c: Buffer) => chunks.push(c));
        req.on('end', () => resolve(Buffer.concat(chunks)));
    });

export async function startFakeLaravel(
    opts: FakeOptions = { leaseSeconds: 2 },
): Promise<FakeLaravel> {
    const runs = new Map<number, FakeRun>();
    const log: string[] = [];
    let nextId = 1;

    const fake: FakeLaravel = {
        origin: '',
        leaseSeconds: opts.leaseSeconds,
        runs,
        log,
        tamperResume: false,
        rejectHeartbeats: false,
        dropHeartbeats: false,
        dropResultsBefore: 0,
        dropResultsAfter: 0,
        rejectResults: false,
        blackholeResults: false,
        enqueue: (o) => {
            const run: FakeRun = {
                id: nextId++,
                status: 'queued',
                identity: null,
                leaseUntil: 0,
                failure: null,
                applicationUrl: o.applicationUrl,
                applicant: o.applicant ?? {
                    full_name: 'Alex Example',
                    first_name: 'Alex',
                    last_name: 'Example',
                    email: 'alex.example@example.test',
                    phone: '+1 555 010 0199',
                },
                resume:
                    o.resume ??
                    Buffer.from('%PDF-1.7\nsynthetic resume bytes\n%%EOF'),
                filename: o.filename ?? 'Alex-Example-Resume-Example-Co.pdf',
                screenshots: new Map(),
                results: [],
                heartbeats: 0,
                accepted: null,
                afterLoss: [],
            };
            runs.set(run.id, run);
            return run;
        },
        lapse: (id) => {
            const r = runs.get(id);
            if (r) r.leaseUntil = Date.now() - 1;
        },
        close: () => new Promise((resolve) => server.close(() => resolve())),
    };

    const settleLapsed = () => {
        for (const r of runs.values())
            if (r.status === 'running' && r.leaseUntil < Date.now()) {
                r.status = 'failed';
                r.failure = 'claim_timeout';
            }
    };

    const send = (
        res: ServerResponse,
        status: number,
        data?: unknown,
        headers: Record<string, string> = {},
    ) => {
        res.writeHead(status, {
            'Content-Type': 'application/json',
            ...headers,
        });
        res.end(data === undefined ? undefined : JSON.stringify(data));
    };
    const entry = (req: IncomingMessage, status: number) =>
        log.push(`${req.method} ${req.url} ${status}`);

    const server: Server = createServer(async (req, res) => {
        const raw = await body(req);
        const url = new URL(req.url ?? '/', 'http://x');
        const reply = (
            status: number,
            data?: unknown,
            headers?: Record<string, string>,
        ) => {
            entry(req, status);
            send(res, status, data, headers);
        };
        const drop = () => {
            entry(req, 0);
            req.socket.destroy();
        };

        if (req.headers.authorization !== `Bearer ${TOKEN}`)
            return reply(401, { message: 'Invalid worker credentials.' });
        const m =
            /^\/api\/worker\/preparer\/agent-runs\/(claim|(\d+)\/(input|resume|heartbeat|result|screenshots\/(.+)))$/.exec(
                url.pathname,
            );
        if (!m) return reply(404);
        settleLapsed();

        if (m[1] === 'claim' && req.method === 'POST') {
            const next = [...runs.values()].find((r) => r.status === 'queued');
            if (!next) {
                entry(req, 204);
                res.writeHead(204);
                return res.end();
            }
            const identity = String(
                JSON.parse(raw.toString() || '{}').worker_identity ?? 'unknown',
            );
            next.status = 'running';
            next.identity = identity;
            next.leaseUntil = Date.now() + fake.leaseSeconds * 1000;
            return reply(200, {
                agent_run_id: next.id,
                application_url: next.applicationUrl,
                lease_expires_at: new Date(next.leaseUntil).toISOString(),
                lease_seconds: fake.leaseSeconds,
                resume: { sha256: sha(next.resume), bytes: next.resume.length },
            });
        }

        const run = runs.get(Number(m[2]));
        if (!run) return reply(404);
        const identity = String(req.headers['x-worker-identity'] ?? '');
        if (run.identity === null || identity !== run.identity)
            return reply(403);
        const lapsed = run.leaseUntil < Date.now();
        if (lapsed && run.status === 'running') {
            run.status = 'failed';
            run.failure = 'claim_timeout';
        }
        const kind = m[3] ?? '';

        // Anything arriving for a run that is no longer live (other than a duplicate result) is a leak the test must see.
        const live = run.status === 'running';
        if (!live && !(kind === 'result' && run.status === 'succeeded'))
            run.afterLoss.push(`${req.method} ${kind}`);

        if (kind === 'result') {
            const payload = JSON.parse(raw.toString() || '{}') as {
                status?: string;
                report?: Record<string, unknown>;
            };
            if (fake.blackholeResults) return drop();
            if (fake.dropResultsBefore > 0) {
                fake.dropResultsBefore--;
                return drop();
            }
            if (fake.rejectResults && payload.status === 'succeeded')
                return reply(422, { message: 'invalid' });
            if (
                payload.status === 'succeeded' &&
                (
                    payload.report?.submission as
                        | { assertion?: string }
                        | undefined
                )?.assertion !== 'worker_did_not_submit'
            )
                return reply(422, { message: 'submission assertion' });
            let status = 200;
            let out: unknown;
            if (live) {
                run.status =
                    payload.status === 'succeeded' ? 'succeeded' : 'failed';
                run.failure =
                    payload.status === 'failed'
                        ? String(
                              (payload as { failure_category?: string })
                                  .failure_category,
                          )
                        : null;
                run.accepted = payload;
                run.results.push(payload);
                out = { accepted: true, duplicate: false };
            } else if (
                (run.status === 'succeeded' &&
                    payload.status === 'succeeded') ||
                (run.status === 'failed' &&
                    payload.status === 'failed' &&
                    run.failure !== 'claim_timeout')
            ) {
                run.results.push(payload);
                out = { accepted: true, duplicate: true };
            } else {
                status = 409;
                out = {
                    accepted: false,
                    reason: 'agent_run_not_running',
                    current_status: run.status,
                };
            }
            if (fake.dropResultsAfter > 0 && status === 200) {
                fake.dropResultsAfter--;
                return drop();
            }
            return reply(status, out);
        }

        if (!live)
            return reply(409, {
                accepted: false,
                reason: 'agent_run_not_running',
                current_status: run.status,
            });

        if (kind === 'input')
            return reply(200, {
                agent_run_id: run.id,
                schema_version: 1,
                application_url: run.applicationUrl,
                applicant: run.applicant,
                answers: {},
                resume: {
                    sha256: sha(run.resume),
                    bytes: run.resume.length,
                    filename: run.filename,
                },
            });
        if (kind === 'resume') {
            const bytes = fake.tamperResume
                ? Buffer.from('%PDF-1.7\nsomeone else\n%%EOF')
                : run.resume;
            entry(req, 200);
            res.writeHead(200, {
                'Content-Type': 'application/pdf',
                'X-Resume-Sha256': sha(run.resume),
            });
            return res.end(bytes);
        }
        if (kind === 'heartbeat') {
            if (fake.dropHeartbeats) return drop();
            if (fake.rejectHeartbeats) {
                run.status = 'failed';
                run.failure = 'claim_timeout';
                return reply(409, {
                    accepted: false,
                    reason: 'agent_run_not_running',
                });
            }
            run.heartbeats++;
            run.leaseUntil = Date.now() + fake.leaseSeconds * 1000;
            return reply(200, {
                accepted: true,
                lease_expires_at: new Date(run.leaseUntil).toISOString(),
            });
        }
        if (kind.startsWith('screenshots/')) {
            const name = m[4] ?? '';
            if (!SHOT.test(name)) return reply(404);
            if (raw.length < 8 || !raw.subarray(0, 8).equals(PNG))
                return reply(422, {
                    accepted: false,
                    reason: 'artifact_not_png',
                });
            const have = run.screenshots.get(name);
            if (have)
                return have.equals(raw)
                    ? reply(200, { accepted: true, duplicate: true })
                    : reply(409, {
                          accepted: false,
                          reason: 'artifact_conflict',
                      });
            run.screenshots.set(name, raw);
            return reply(200, { accepted: true, duplicate: false });
        }
        return reply(404);
    });

    await new Promise<void>((resolve) =>
        server.listen(0, '127.0.0.1', resolve),
    );
    const addr = server.address();
    fake.origin = `http://127.0.0.1:${typeof addr === 'object' && addr ? addr.port : 0}`;
    return fake;
}

export { PNG };
