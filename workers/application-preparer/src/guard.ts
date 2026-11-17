// Submission safeguards. Layered; none of the layers depends on the others:
//
//  1. Capability: this worker has no click/press/submit API (see fill.ts
//     and test/no-capability.test.ts).
//  2. Page events: guard-init.ts cancels `submit` events and no-ops
//     form.submit()/requestSubmit(), reporting each attempt here.
//  3. Network: every non-GET/HEAD/OPTIONS request is aborted unless it
//     matches a narrow, named UploadException.
//  4. Navigation: once the form has loaded, any main-frame navigation
//     (incl. a GET form submission or a link) is aborted; popups are closed.
//  5. Outcome: any event other than a named exception or third-party noise
//     makes the run a `safety_failure` (see prepare.ts), and a page that
//     looks like a confirmation page is flagged as a suspected submission.
//
// Limitations are listed in README.md "Safety controls and limits".
import type {
    BrowserContext,
    Page,
    Request,
    Response,
    Route,
} from 'playwright';
import {
    SAFETY_FAILURE_KINDS,
    type SafetyEvent,
    type UploadResponseEvidence,
    type SafetyEventKind,
} from './contracts.ts';
import { GUARD_INIT_SCRIPT } from './guard-init.ts';
import {
    isCloudflareBotDetection,
    isGreenhouseTelemetry,
    matchException,
    mediaType,
    type LearnRule,
    type RequestFacts,
    type UploadException,
} from './request-policy.ts';
import { atsForHost } from './url-safety.ts';

export type { LearnRule, UploadException } from './request-policy.ts';

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

/** One request an exception let through, and what became of it. Facts only: no body, no headers but length. */
interface UploadAttempt extends UploadResponseEvidence {
    startedAt: number;
    settled: boolean;
}

export class SubmissionGuard {
    readonly events: SafetyEvent[] = [];
    phase: SafetyEvent['phase'] = 'preparation';
    private navigationOpen = true;
    private mainPage: Page | null = null;
    private readonly startHost: string;
    private readonly exceptions: readonly UploadException[];
    private readonly startOrigin: string;
    private uploadWindowOpen = false;
    private readonly uses = new Map<string, number>();
    private readonly learnRules: readonly LearnRule[];
    /** Destinations (origin + pathname) named by the page's own authorisation responses. */
    private readonly learned: Record<string, string[]> = {};
    private readonly learning: Promise<void>[] = [];
    private readonly attempts = new Map<Request, UploadAttempt>();
    private readonly refusals: string[] = [];
    private readonly waiters = new Set<() => void>();

    constructor(
        startUrl: URL,
        exceptions: readonly UploadException[] = [],
        learn: readonly LearnRule[] = [],
    ) {
        this.startHost = startUrl.hostname;
        this.startOrigin = startUrl.origin;
        this.exceptions = exceptions;
        this.learnRules = learn;
        for (const e of exceptions) {
            const src = e.pathPattern.source;
            if (!src.startsWith('^') || !src.endsWith('$')) {
                throw new Error(
                    `UploadException "${e.name}": pathPattern must be anchored with ^ and $`,
                );
            }
            if (!e.why.trim())
                throw new Error(
                    `UploadException "${e.name}": a justification is required`,
                );
        }
    }

    async install(context: BrowserContext): Promise<void> {
        await context.exposeBinding(
            '__waSubmitBlocked',
            (_source, detail: unknown) => {
                this.record('submit_event_blocked', String(detail));
            },
        );
        await context.addInitScript(GUARD_INIT_SCRIPT);
        await context.route('**/*', (route) => this.handle(route));
        context.on('response', (response) => {
            this.noteResponse(response);
            if (this.learnRules.length === 0) return;
            const learning = this.learnFrom(response);
            if (learning) this.learning.push(learning);
        });
        context.on('requestfailed', (request) => {
            const attempt = this.attempts.get(request);
            if (!attempt || attempt.settled) return;
            attempt.settled = true;
            attempt.failure = request.failure()?.errorText ?? 'request failed';
            attempt.durationMs = Date.now() - attempt.startedAt;
            this.wake();
        });
        context.on('page', (page) => {
            if (this.mainPage !== null && page !== this.mainPage) {
                this.record(
                    'unexpected_navigation',
                    'a popup/new page was opened and closed',
                );
                void page.close().catch(() => {});
            }
        });
    }

    /** Call with the page the run uses, before its first navigation. */
    setMainPage(page: Page): void {
        this.mainPage = page;
    }

    /** After the form has loaded (and any deterministic URL rewrite), no further navigation is allowed. */
    lockNavigation(): void {
        this.navigationOpen = false;
    }

    hasFailure(): boolean {
        return this.events.some((e) => SAFETY_FAILURE_KINDS.has(e.kind));
    }

    record(kind: SafetyEventKind, detail: string): void {
        this.events.push({
            kind,
            detail,
            at: new Date().toISOString(),
            phase: this.phase,
        });
    }

    private isAtsFamily(host: string): boolean {
        return (
            host === this.startHost ||
            atsForHost(host) !== null ||
            /(^|\.)(lever\.co|greenhouse\.io)$/.test(host)
        );
    }

    private async handle(route: Route): Promise<void> {
        const request = route.request();
        const url = new URL(request.url());
        const method = request.method().toUpperCase();

        if (this.isMainFrameNavigation(request)) {
            if (this.navigationOpen && this.isAtsFamily(url.hostname))
                return void (await route.continue());
            this.record('unexpected_navigation', `${method} ${redact(url)}`);
            return void (await route.abort('blockedbyclient'));
        }

        if (SAFE_METHODS.has(method)) return void (await route.continue());

        await this.settleLearning();
        const facts = this.factsFor(request, url);
        let nearMiss: string | null = null;
        for (const e of this.exceptions) {
            const result = matchException(e, {
                ...facts,
                priorUses: this.uses.get(e.name) ?? 0,
            });
            if (result.matched) {
                this.uses.set(e.name, (this.uses.get(e.name) ?? 0) + 1);
                this.record(
                    'allowed_exception_used',
                    `${e.name}: ${describe(facts)}`,
                );
                this.attempts.set(request, {
                    exception: e.name,
                    destination: redact(url),
                    status: null,
                    statusText: null,
                    contentLength: null,
                    failure: null,
                    durationMs: null,
                    startedAt: Date.now(),
                    settled: false,
                });
                return void (await route.continue());
            }
            nearMiss ??=
                result.nearMiss === null
                    ? null
                    : `exception "${e.name}" refused: ${result.nearMiss}`;
        }

        // Recognised, still blocked, and not a submission signal.
        if (isCloudflareBotDetection(method, url, this.startOrigin)) {
            this.record(
                'blocked_bot_detection_request',
                `cloudflare jsd oneshot beacon: ${describe(facts)}`,
            );
            return void (await route.abort('blockedbyclient'));
        }

        // Still blocked, and reported as noise rather than as a possible submission: the single
        // analytics beacon observed on live Greenhouse boards. Nothing else on that host qualifies.
        if (
            isGreenhouseTelemetry(
                method,
                url,
                facts.resourceType,
                facts.contentType,
            )
        ) {
            this.record(
                'blocked_telemetry_request',
                `greenhouse snowplow analytics beacon: ${describe(facts)}`,
            );
            return void (await route.abort('blockedbyclient'));
        }

        // A refused request that looks like an upload, while the worker is attaching the resume,
        // is reported as such (never loosened): a partial match of an exception, or a multipart body.
        if (
            this.uploadWindowOpen &&
            (nearMiss !== null ||
                mediaType(facts.contentType) === 'multipart/form-data')
        ) {
            this.refusals.push(`${method} ${redact(url)}`);
            this.wake();
        }

        const kind: SafetyEventKind = this.isAtsFamily(url.hostname)
            ? 'blocked_mutating_request'
            : 'blocked_third_party_request';
        this.record(
            kind,
            `${describe(facts)}${nearMiss ? ` (${nearMiss})` : ''}`,
        );
        await route.abort('blockedbyclient');
    }

    /**
     * If this response is one a LearnRule trusts (a GET answered 200 by an exact ATS origin
     * at the rule's path), remember ONLY the destination URL it names. The body is read here
     * and dropped: signed upload fields are never stored or reported.
     */
    private learnFrom(response: Response): Promise<void> | null {
        try {
            const request = response.request();
            if (request.method() !== 'GET' || response.status() !== 200)
                return null;
            const url = new URL(response.url());
            const rule = this.learnRules.find(
                (r) =>
                    r.pageOrigins.includes(url.origin) &&
                    url.pathname.endsWith(r.pathSuffix),
            );
            if (!rule) return null;
            const length = Number(response.headers()['content-length'] ?? 0);
            if (length > 256 * 1024) return null;
            return (async () => {
                try {
                    const dest = rule.destination(await response.json());
                    if (dest === null) return;
                    const u = new URL(dest);
                    (this.learned[rule.name] ??= []).push(
                        `${u.origin}${u.pathname}`,
                    );
                } catch {
                    // Not JSON, or not shaped as expected: nothing is learned.
                }
            })();
        } catch {
            return null;
        }
    }

    /** A request can arrive before the response that authorises it has been parsed; wait briefly. */
    private async settleLearning(): Promise<void> {
        if (this.learning.length === 0) return;
        await Promise.race([
            Promise.allSettled(this.learning),
            new Promise((r) => setTimeout(r, 2_000)),
        ]);
    }

    /** The worker is itself attaching the resume; exceptions marked duringUploadOnly may fire only now. */
    openUploadWindow(): void {
        this.uploadWindowOpen = true;
    }

    closeUploadWindow(): void {
        this.uploadWindowOpen = false;
    }

    private noteResponse(response: Response): void {
        const attempt = this.attempts.get(response.request());
        if (!attempt || attempt.settled) return;
        attempt.settled = true;
        attempt.status = response.status();
        attempt.statusText = response.statusText() || null;
        const length = Number(response.headers()['content-length']);
        attempt.contentLength = Number.isFinite(length) ? length : null;
        attempt.durationMs = Date.now() - attempt.startedAt;
        this.wake();
    }

    private wake(): void {
        for (const w of [...this.waiters]) w();
    }

    /** What the allowed upload request(s) of the named exception got back so far. */
    uploadResponse(name: string): UploadResponseEvidence | null {
        const mine = [...this.attempts.values()].filter(
            (a) => a.exception === name,
        );
        const a = mine[mine.length - 1];
        if (!a) return null;
        return {
            exception: a.exception,
            destination: a.destination,
            status: a.status,
            statusText: a.statusText,
            contentLength: a.contentLength,
            failure: a.failure,
            durationMs: a.durationMs,
        };
    }

    /** Upload-like requests refused since the window opened (method, origin and path only). */
    uploadRefusals(): string[] {
        return [...this.refusals];
    }

    /**
     * Resolves as soon as the named exception's request has a response or failed, or an upload-like
     * request was refused, whichever is first; otherwise after `timeoutMs`. Event-driven: no fixed delay.
     */
    async awaitUploadSettled(name: string, timeoutMs: number): Promise<void> {
        const done = () =>
            this.refusals.length > 0 ||
            [...this.attempts.values()].some(
                (a) => a.exception === name && a.settled,
            );
        if (done()) return;
        await new Promise<void>((resolve) => {
            const finish = () => {
                clearTimeout(timer);
                this.waiters.delete(check);
                resolve();
            };
            const check = () => {
                if (done()) finish();
            };
            const timer = setTimeout(finish, timeoutMs);
            this.waiters.add(check);
        });
    }

    /** How many times the named exception has let a request through (read-only; used for reporting). */
    exceptionUses(name: string): number {
        return this.uses.get(name) ?? 0;
    }

    private factsFor(
        request: Request,
        url: URL,
    ): Omit<RequestFacts, 'priorUses'> {
        let isMainFrame = false;
        try {
            isMainFrame = request.frame().parentFrame() === null;
        } catch {
            isMainFrame = false;
        }
        return {
            method: request.method().toUpperCase(),
            url,
            resourceType: request.resourceType(),
            contentType: request.headers()['content-type'] ?? null,
            bodyBytes: request.postDataBuffer()?.length ?? null,
            body: request.postDataBuffer(),
            learned: this.learned,
            isMainFrame,
            uploadWindowOpen: this.uploadWindowOpen,
        };
    }

    private isMainFrameNavigation(request: Request): boolean {
        if (!request.isNavigationRequest()) return false;
        try {
            return request.frame().parentFrame() === null;
        } catch {
            // No frame available (e.g. a popup's first request): fail closed, treat as a main-frame navigation.
            return true;
        }
    }
}

/** Method, redacted URL and request shape (never the body, never the boundary). */
function describe(f: Omit<RequestFacts, 'priorUses'>): string {
    return `${f.method} ${redact(f.url)} [${f.resourceType}, ${mediaType(f.contentType) ?? 'no content-type'}, ${f.bodyBytes ?? '?'}B]`;
}

/** Never put query strings (which can carry tokens or applicant data) in a report. */
function redact(url: URL): string {
    return `${url.origin}${url.pathname}`;
}
