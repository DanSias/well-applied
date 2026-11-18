// Thin HTTP client for Well Applied's preparer protocol (/api/worker/preparer/*). It has nothing to do with Playwright
// and the token never leaves this module: the browser engine runs in a separate process that is not given it.
// Every call returns { status, body } for any HTTP response; only a transport failure (no response) throws.
import type { AgentConfig } from './config.ts';

export interface ClaimResponse {
    agent_run_id: number;
    application_url: string | null;
    lease_expires_at: string | null;
    lease_seconds: number;
    resume: { sha256: string | null; bytes: number | null };
}

export interface Reply<T = unknown> {
    status: number;
    body: T | null;
}

export interface PreparerClient {
    claim(): Promise<Reply<ClaimResponse>>;
    input(id: number): Promise<Reply<Record<string, unknown>>>;
    resume(id: number): Promise<{
        status: number;
        bytes: Buffer | null;
        sha256Header: string | null;
    }>;
    heartbeat(
        id: number,
        phase?: string,
    ): Promise<Reply<{ accepted: boolean }>>;
    putScreenshot(
        id: number,
        name: string,
        bytes: Buffer,
    ): Promise<Reply<{ accepted?: boolean; reason?: string }>>;
    result(
        id: number,
        payload: unknown,
    ): Promise<
        Reply<{ accepted?: boolean; duplicate?: boolean; reason?: string }>
    >;
}

const REQUEST_TIMEOUT_MS = 30_000;

export function createClient(
    cfg: Pick<AgentConfig, 'baseUrl' | 'token' | 'identity'>,
    fetchImpl: typeof fetch = fetch,
): PreparerClient {
    const base = `${cfg.baseUrl}/api/worker/preparer`;
    const headers = (extra: Record<string, string> = {}) => ({
        Authorization: `Bearer ${cfg.token}`,
        'X-Worker-Identity': cfg.identity,
        Accept: 'application/json',
        ...extra,
    });
    const json = async <T>(res: Response): Promise<Reply<T>> => {
        let body: T | null = null;
        try {
            body = (await res.json()) as T;
        } catch {
            // No JSON (204, or an HTML error page): the status is what matters.
        }
        return { status: res.status, body };
    };
    const send = (url: string, init: RequestInit) =>
        fetchImpl(url, {
            ...init,
            signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
        });

    return {
        claim: async () =>
            json(
                await send(`${base}/agent-runs/claim`, {
                    method: 'POST',
                    headers: headers({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ worker_identity: cfg.identity }),
                }),
            ),
        input: async (id) =>
            json(
                await send(`${base}/agent-runs/${id}/input`, {
                    headers: headers(),
                }),
            ),
        resume: async (id) => {
            const res = await send(`${base}/agent-runs/${id}/resume`, {
                headers: headers(),
            });
            return {
                status: res.status,
                bytes: res.ok ? Buffer.from(await res.arrayBuffer()) : null,
                sha256Header: res.headers.get('X-Resume-Sha256'),
            };
        },
        heartbeat: async (id, phase) =>
            json(
                await send(`${base}/agent-runs/${id}/heartbeat`, {
                    method: 'POST',
                    headers: headers({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify(phase ? { phase } : {}),
                }),
            ),
        putScreenshot: async (id, name, bytes) =>
            json(
                await send(`${base}/agent-runs/${id}/screenshots/${name}`, {
                    method: 'PUT',
                    headers: headers({ 'Content-Type': 'image/png' }),
                    body: new Uint8Array(bytes),
                }),
            ),
        result: async (id, payload) =>
            json(
                await send(`${base}/agent-runs/${id}/result`, {
                    method: 'POST',
                    headers: headers({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify(payload),
                }),
            ),
    };
}
