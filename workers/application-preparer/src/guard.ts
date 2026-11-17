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
import type { BrowserContext, Page, Request, Route } from 'playwright';
import { SAFETY_FAILURE_KINDS, type SafetyEvent, type SafetyEventKind } from './contracts.ts';
import { GUARD_INIT_SCRIPT } from './guard-init.ts';
import { atsForHost } from './url-safety.ts';

/**
 * A narrowly scoped permission for ONE non-GET request. Everything must
 * match: method, exact origin, and a fully anchored path pattern. Each one
 * needs a name and a justification, and a test in test/guard.test.ts.
 */
export interface UploadException {
    name: string;
    method: 'POST' | 'PUT';
    origin: string;
    /** Must be anchored (^...$); enforced when the guard is constructed. */
    pathPattern: RegExp;
    why: string;
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

export class SubmissionGuard {
    readonly events: SafetyEvent[] = [];
    phase: SafetyEvent['phase'] = 'preparation';
    private navigationOpen = true;
    private mainPage: Page | null = null;
    private readonly startHost: string;
    private readonly exceptions: readonly UploadException[];

    constructor(startUrl: URL, exceptions: readonly UploadException[] = []) {
        this.startHost = startUrl.hostname;
        this.exceptions = exceptions;
        for (const e of exceptions) {
            const src = e.pathPattern.source;
            if (!src.startsWith('^') || !src.endsWith('$')) {
                throw new Error(`UploadException "${e.name}": pathPattern must be anchored with ^ and $`);
            }
            if (!e.why.trim()) throw new Error(`UploadException "${e.name}": a justification is required`);
        }
    }

    async install(context: BrowserContext): Promise<void> {
        await context.exposeBinding('__waSubmitBlocked', (_source, detail: unknown) => {
            this.record('submit_event_blocked', String(detail));
        });
        await context.addInitScript(GUARD_INIT_SCRIPT);
        await context.route('**/*', (route) => this.handle(route));
        context.on('page', (page) => {
            if (this.mainPage !== null && page !== this.mainPage) {
                this.record('unexpected_navigation', 'a popup/new page was opened and closed');
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
        this.events.push({ kind, detail, at: new Date().toISOString(), phase: this.phase });
    }

    private isAtsFamily(host: string): boolean {
        return host === this.startHost || atsForHost(host) !== null || /(^|\.)(lever\.co|greenhouse\.io)$/.test(host);
    }

    private async handle(route: Route): Promise<void> {
        const request = route.request();
        const url = new URL(request.url());
        const method = request.method().toUpperCase();

        if (this.isMainFrameNavigation(request)) {
            if (this.navigationOpen && this.isAtsFamily(url.hostname)) return void (await route.continue());
            this.record('unexpected_navigation', `${method} ${redact(url)}`);
            return void (await route.abort('blockedbyclient'));
        }

        if (SAFE_METHODS.has(method)) return void (await route.continue());

        const exception = this.exceptions.find(
            (e) => e.method === method && e.origin === url.origin && e.pathPattern.test(url.pathname),
        );
        if (exception) {
            this.record('allowed_exception_used', `${exception.name}: ${method} ${redact(url)}`);
            return void (await route.continue());
        }

        const kind: SafetyEventKind = this.isAtsFamily(url.hostname) ? 'blocked_mutating_request' : 'blocked_third_party_request';
        this.record(kind, `${method} ${redact(url)}`);
        await route.abort('blockedbyclient');
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

/** Never put query strings (which can carry tokens or applicant data) in a report. */
function redact(url: URL): string {
    return `${url.origin}${url.pathname}`;
}
