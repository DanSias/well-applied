// The preparation engine: one run = one browser, one page, one form.
//
//   validate input -> open form (GET only) -> lock navigation -> discover
//   -> attach resume (first: some forms auto-fill from it) -> re-discover
//   -> classify + fill + verify each field -> evidence -> decide outcome
//   -> optionally hold for a human.
//
// It never clicks a button and never submits; see guard.ts and fill.ts.
// Shared by every ATS: adapters only say where the form is.
import { createHash, randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';
import { chromium, type Browser, type Page } from 'playwright';
import { classify } from './classify.ts';
import {
    SAFETY_FAILURE_KINDS,
    SCHEMA_VERSION,
    type AtsName,
    type FieldResult,
    type Outcome,
    type PrepareInput,
    type PrepareReport,
} from './contracts.ts';
import { discoverForm, type Discovery } from './discover.ts';
import { createRunDir, screenshot, writeReport } from './evidence.ts';
import { chooseRadio, fillText, selectOption, uploadResumeFile } from './fill.ts';
import { SubmissionGuard, type UploadException } from './guard.ts';
import { adapterFor } from './adapters/index.ts';
import { assertAllowedUrl, atsForHost } from './url-safety.ts';
import { observeFileInput, readCheckedRadio, readPageSignals, readSelectedOption, readTextValue } from './verify.ts';

export interface PrepareOptions {
    headless?: boolean;
    /** Keep the (headed) browser open for a human until it is closed or Enter is pressed. */
    hold?: boolean;
    runsDir?: string;
    /** Fixture testing only: allow http://127.0.0.1 and require `ats` to be given. */
    allowLocalFixture?: boolean;
    ats?: AtsName;
    /** Test-only override of the adapter's (normally empty) upload exceptions. */
    uploadExceptions?: UploadException[];
    /** Resolves when a held session should end. Defaults to browser close / Enter. */
    holdUntil?: (page: Page, browser: Browser) => Promise<void>;
}

const MAX_RESUME_BYTES = 10 * 1024 * 1024;
const RUN_ID = /^[A-Za-z0-9_-]{1,64}$/;

export interface PrepareResult {
    report: PrepareReport;
    reportPath: string;
    runDir: string;
}

export async function prepareApplication(input: PrepareInput, options: PrepareOptions = {}): Promise<PrepareResult> {
    const startedAt = new Date();
    const runId = input.runId ?? `run-${startedAt.toISOString().replace(/[-:.TZ]/g, '').slice(0, 14)}-${randomBytes(3).toString('hex')}`;
    if (!RUN_ID.test(runId)) throw new Error('runId must match [A-Za-z0-9_-]{1,64}');
    if (input.schemaVersion !== SCHEMA_VERSION) throw new Error(`unsupported schemaVersion ${String(input.schemaVersion)}`);

    const resume = await loadResume(input.resumePdfPath);
    const runsDir = options.runsDir ?? 'runs';
    const runDir = await createRunDir(runsDir, runId);
    const headless = options.headless ?? false;

    const report: PrepareReport = {
        schemaVersion: SCHEMA_VERSION,
        runId,
        startedAt: startedAt.toISOString(),
        finishedAt: startedAt.toISOString(),
        ats: null,
        requestedUrl: redactUrl(input.applicationUrl),
        finalUrl: null,
        outcome: 'failed',
        reasons: [],
        fields: [],
        summary: { total: 0, filledVerified: 0, unansweredOptional: 0, unresolvedRequired: 0, unresolvedUncertain: 0, errors: 0 },
        resume: { sha256: resume.sha256, bytes: resume.bytes.length, fieldId: null, uploaded: false, verification: 'none', verified: false, observedFilename: null },
        controls: [],
        blockers: { captcha: false, authentication: false, multistep: false },
        submission: { assertion: 'worker_did_not_submit', blockedAttempts: 0, suspected: false },
        safetyEvents: [],
        screenshots: [],
        warnings: [],
        diagnostics: { durationMs: 0, headless, held: false },
    };
    const finish = async (outcome: Outcome, reasons: string[]): Promise<PrepareResult> => {
        report.outcome = outcome;
        report.reasons = reasons;
        report.finishedAt = new Date().toISOString();
        report.diagnostics.durationMs = Date.now() - startedAt.getTime();
        return { report, reportPath: await writeReport(runDir, report), runDir };
    };

    let url: URL;
    try {
        url = assertAllowedUrl(input.applicationUrl, { allowLocalFixture: options.allowLocalFixture });
    } catch (e) {
        return finish('unsupported', [(e as Error).message]);
    }
    const ats = options.allowLocalFixture ? options.ats ?? null : atsForHost(url.hostname);
    if (ats === null) return finish('unsupported', ['no ATS adapter for this URL (local fixtures must pass `ats`)']);
    report.ats = ats;
    const adapter = adapterFor(ats);
    const formUrl = adapter.formUrl(url);

    const guard = new SubmissionGuard(formUrl, options.uploadExceptions ?? adapter.uploadExceptions(formUrl.origin));
    const browser = await chromium.launch({ headless });
    try {
        const context = await browser.newContext({
            viewport: { width: 1400, height: 1000 },
            serviceWorkers: 'block', // a service worker could bypass request interception
            acceptDownloads: false,
        });
        await guard.install(context);
        const page = await context.newPage();
        guard.setMainPage(page);

        await page.goto(formUrl.toString(), { waitUntil: 'domcontentloaded', timeout: 45_000 });
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => report.warnings.push('networkidle_timeout'));
        await page.waitForTimeout(1_500); // let SPA forms render
        guard.lockNavigation();

        report.screenshots.push(...(await shot(page, runDir, '01-before')));

        let discovery = await discoverForm(page);
        const resumeField = discovery.fields.find((f) => f.kind === 'file' && classify(f, input).mappedTo === 'resume') ?? null;

        // The resume goes first: some forms parse it and overwrite other fields.
        if (resumeField !== null) {
            report.resume.fieldId = resumeField.id;
            try {
                await uploadResumeFile(page, resumeField.id, input.resumePdfPath);
                report.resume.uploaded = true;
            } catch (e) {
                report.warnings.push(`resume upload failed: ${firstLine(e)}`);
            }
            await page.waitForTimeout(1_000);
            discovery = await discoverForm(page);
        }
        report.warnings.push(...discovery.warnings);

        report.fields = await processFields(page, input, discovery, report.resume.fieldId);
        await verifyResume(page, report, resume.sha256, input.resumePdfPath);

        report.controls = discovery.controls;
        report.blockers = {
            captcha: discovery.captchaVisible,
            authentication: discovery.passwordField,
            multistep: discovery.controls.some((c) => c.role === 'navigation'),
        };
        if (discovery.captchaInvisible) report.warnings.push('invisible captcha markup present (expected at submission time; not attempted)');

        await page.waitForTimeout(500); // let any blocked request register
        const signals = await readPageSignals(page);
        report.finalUrl = redactUrl(signals.url);
        if (signals.confirmationText) guard.record('suspected_submission', `confirmation-style text on page: "${signals.confirmationText}"`);
        else if (!signals.url.startsWith('chrome-error:') && new URL(signals.url).pathname !== formUrl.pathname) {
            guard.record('suspected_submission', `page path changed from ${formUrl.pathname} to ${new URL(signals.url).pathname}`);
        }
        report.screenshots.push(...(await shot(page, runDir, '02-after-fill')));

        syncSafety(report, guard);
        const decision = decide(report);
        const result = await finish(decision.outcome, decision.reasons);

        if (options.hold) {
            report.diagnostics.held = true;
            guard.phase = 'human_hold';
            process.stderr.write(
                `\nHOLD: run ${runId} outcome=${report.outcome}. Review the form in the browser window.\n` +
                    `The guard stays active: submitting from this window is blocked by design.\n` +
                    `Close the browser window or press Enter here to end.\n\n`,
            );
            await (options.holdUntil ?? defaultHold)(page, browser);
            report.screenshots.push(...(await shot(page, runDir, '03-end-of-hold').catch(() => [])));
            syncSafety(report, guard);
            const final = decide(report);
            return finish(final.outcome, final.reasons);
        }
        return result;
    } catch (e) {
        syncSafety(report, guard);
        return finish(guard.hasFailure() ? 'safety_failure' : 'failed', [firstLine(e)]);
    } finally {
        await browser.close().catch(() => {});
    }
}

async function processFields(page: Page, input: PrepareInput, discovery: Discovery, resumeFieldId: string | null): Promise<FieldResult[]> {
    const results: FieldResult[] = [];
    for (const field of discovery.fields) {
        const c = classify(field, input);
        const result: FieldResult = {
            ...field,
            sensitive: c.sensitive,
            classification: c.classification,
            mappedTo: c.mappedTo,
            status: 'unanswered_optional',
            ...(c.note ? { note: c.note } : {}),
        };
        results.push(result);
        const required = field.required === true;

        if (field.id === resumeFieldId) {
            result.status = 'unresolved_required'; // replaced by verifyResume()
            continue;
        }
        if (c.classification !== 'known') {
            if (c.classification === 'uncertain') result.status = required ? 'unresolved_uncertain' : 'unanswered_optional';
            else result.status = required ? 'unresolved_required' : 'unanswered_optional';
            continue;
        }

        try {
            if ((field.kind === 'text' || field.kind === 'textarea') && c.value !== undefined) {
                await fillText(page, field.id, c.value);
                const actual = await readTextValue(page, field.id);
                result.expected = c.value;
                result.actual = actual;
                result.status = actual === c.value ? 'filled_verified' : 'error';
                if (result.status === 'error') result.note = 'value read back does not match';
            } else if (field.kind === 'select' && c.value !== undefined) {
                await selectOption(page, field.id, c.value);
                const actual = await readSelectedOption(page, field.id);
                result.expected = c.value;
                result.actual = actual ?? '';
                result.status = actual === c.value ? 'filled_verified' : 'error';
            } else if (field.kind === 'radio' && c.optionIndex !== undefined && c.value !== undefined) {
                await chooseRadio(page, field.id, c.optionIndex);
                const checked = await readCheckedRadio(page, field.id);
                result.expected = c.value;
                result.actual = checked >= 0 ? (field.options?.[checked] ?? '') : '';
                result.status = checked === c.optionIndex ? 'filled_verified' : 'error';
            } else {
                result.status = 'error';
                result.note = 'no fill action for this field kind';
            }
        } catch (e) {
            result.status = 'error';
            result.note = `fill failed: ${firstLine(e)}`;
        }
    }
    return results;
}

async function verifyResume(page: Page, report: PrepareReport, expectedSha: string, path: string): Promise<void> {
    const id = report.resume.fieldId;
    if (id === null) return;
    const expectedName = basename(path);
    const field = report.fields.find((f) => f.id === id);
    const obs = await observeFileInput(page, id, expectedName).catch(() => null);
    if (obs === null || !report.resume.uploaded) {
        report.resume.verification = 'none';
    } else if (obs.sha256 === expectedSha) {
        report.resume.verification = 'content_sha256';
        report.resume.verified = true;
    } else if (obs.sha256 === null && obs.name === expectedName && obs.size === report.resume.bytes) {
        report.resume.verification = 'name_size';
        report.resume.verified = true;
    } else if (obs.name === null && obs.nameInPageText) {
        report.resume.verification = 'page_text';
    }
    report.resume.observedFilename = obs?.name ?? null;
    if (field) {
        field.expected = expectedName;
        field.actual = obs?.name ?? '';
        field.status = report.resume.verified ? 'filled_verified' : 'error';
        if (!report.resume.verified) field.note = `resume not verified (${report.resume.verification})`;
    }
}

/** Pure: exported for tests. Priority: safety > unsupported > blocked > input_required > ready. */
export function decide(report: PrepareReport): { outcome: Outcome; reasons: string[] } {
    const failures = report.safetyEvents.filter((e) => SAFETY_FAILURE_KINDS.has(e.kind));
    if (failures.length > 0) {
        return { outcome: 'safety_failure', reasons: failures.map((f) => `${f.kind}: ${f.detail}`) };
    }
    if (report.fields.length === 0) return { outcome: 'unsupported', reasons: ['no form fields were found'] };

    const blocked: string[] = [];
    if (report.blockers.captcha) blocked.push('a visible captcha needs a human');
    if (report.blockers.authentication) blocked.push('a login/password field is present');
    if (report.blockers.multistep) blocked.push('the form has Next/Continue steps; multistep forms are not handled');
    if (report.resume.fieldId === null) blocked.push('no resume upload field was recognised');
    else if (!report.resume.verified) blocked.push(`resume upload could not be verified (${report.resume.verification})`);
    if (blocked.length > 0) return { outcome: 'blocked', reasons: blocked };

    const input = report.fields.filter((f) => f.status === 'unresolved_required' || f.status === 'unresolved_uncertain' || f.status === 'error');
    if (input.length > 0) {
        return { outcome: 'input_required', reasons: input.map((f) => `${f.status}: ${f.label ?? f.name ?? f.id}`) };
    }
    return { outcome: 'ready_for_review', reasons: [] };
}

function syncSafety(report: PrepareReport, guard: SubmissionGuard): void {
    report.safetyEvents = [...guard.events];
    report.submission.blockedAttempts = guard.events.filter((e) => SAFETY_FAILURE_KINDS.has(e.kind) && e.kind !== 'suspected_submission').length;
    report.submission.suspected = guard.events.some((e) => e.kind === 'suspected_submission');
    const s = report.summary;
    s.total = report.fields.length;
    s.filledVerified = report.fields.filter((f) => f.status === 'filled_verified').length;
    s.unansweredOptional = report.fields.filter((f) => f.status === 'unanswered_optional').length;
    s.unresolvedRequired = report.fields.filter((f) => f.status === 'unresolved_required').length;
    s.unresolvedUncertain = report.fields.filter((f) => f.status === 'unresolved_uncertain').length;
    s.errors = report.fields.filter((f) => f.status === 'error').length;
}

async function loadResume(path: string): Promise<{ bytes: Buffer; sha256: string }> {
    const bytes = await readFile(path);
    if (bytes.length === 0 || bytes.length > MAX_RESUME_BYTES) throw new Error('resume PDF is empty or larger than 10 MB');
    if (bytes.subarray(0, 5).toString('latin1') !== '%PDF-') throw new Error('resume file is not a PDF');
    return { bytes, sha256: createHash('sha256').update(bytes).digest('hex') };
}

async function shot(page: Page, dir: string, name: string): Promise<string[]> {
    const file = await screenshot(page, dir, name);
    return file ? [file] : [];
}

/** Query strings can carry tokens; reports keep origin + path only. */
function redactUrl(raw: string): string {
    try {
        const u = new URL(raw);
        return `${u.origin}${u.pathname}`;
    } catch {
        return '(invalid url)';
    }
}

function firstLine(e: unknown): string {
    return String((e as Error)?.message ?? e).split('\n')[0] ?? 'unknown error';
}

function defaultHold(page: Page, browser: Browser): Promise<void> {
    return new Promise((resolve) => {
        const done = () => resolve();
        page.once('close', done);
        browser.once('disconnected', done);
        if (process.stdin.isTTY) process.stdin.once('data', done);
    });
}
