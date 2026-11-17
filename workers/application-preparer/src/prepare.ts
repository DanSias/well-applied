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
    REPORT_SCHEMA_VERSION,
    SCHEMA_VERSION,
    type AtsName,
    type FieldResult,
    type FinalState,
    type HoldEnd,
    type Outcome,
    type ScreenshotPhase,
    type PrepareInput,
    type PrepareReport,
} from './contracts.ts';
import { discoverForm, type Discovery } from './discover.ts';
import { locateField, type Located } from './identity.ts';
import { captureViewports, createRunDir, writeReport } from './evidence.ts';
import { stopFilePath, waitForHoldEnd } from './hold.ts';
import { observeParser } from './parser.ts';
import {
    chooseRadio,
    fillText,
    selectOption,
    uploadResumeFile,
} from './fill.ts';
import { SubmissionGuard } from './guard.ts';
import type { LearnRule, UploadException } from './request-policy.ts';
import { adapterFor } from './adapters/index.ts';
import { assertAllowedUrl, atsForHost } from './url-safety.ts';
import {
    judgeText,
    worse,
    type ControlState,
    type TextVerdict,
} from './verdict.ts';
import {
    observeFileInput,
    readCheckedRadio,
    readControlState,
    readDisplayedValue,
    readPageSignals,
    readSelectedOption,
} from './verify.ts';

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
    /** Test-only override of the adapter's authorisation-learning rules. */
    authorizationRules?: LearnRule[];
    /**
     * Resolves when a held session should end. The default waits for Enter (interactive),
     * the STOP file in the run directory, or the window/browser closing (see hold.ts).
     * Returns how it ended, or nothing (recorded as 'callback').
     */
    holdUntil?: (
        page: Page,
        browser: Browser,
        ctx: { stopFile: string },
    ) => Promise<HoldEnd | void>;
    /** Bounded wait for the resume parser's visible terminal state during preparation. Default 15000. */
    parserWaitMs?: number;
    /** Bounded wait at the end of a hold if the parser was still unresolved. Default 5000. */
    finishParserWaitMs?: number;
}

const MAX_RESUME_BYTES = 10 * 1024 * 1024;
const RUN_ID = /^[A-Za-z0-9_-]{1,64}$/;

export interface PrepareResult {
    report: PrepareReport;
    reportPath: string;
    runDir: string;
}

export async function prepareApplication(
    input: PrepareInput,
    options: PrepareOptions = {},
): Promise<PrepareResult> {
    const startedAt = new Date();
    const runId =
        input.runId ??
        `run-${startedAt
            .toISOString()
            .replace(/[-:.TZ]/g, '')
            .slice(0, 14)}-${randomBytes(3).toString('hex')}`;
    if (!RUN_ID.test(runId))
        throw new Error('runId must match [A-Za-z0-9_-]{1,64}');
    if (input.schemaVersion !== SCHEMA_VERSION)
        throw new Error(
            `unsupported schemaVersion ${String(input.schemaVersion)}`,
        );

    const resume = await loadResume(input.resumePdfPath);
    const runsDir = options.runsDir ?? 'runs';
    const runDir = await createRunDir(runsDir, runId);
    const headless = options.headless ?? false;

    const report: PrepareReport = {
        schemaVersion: REPORT_SCHEMA_VERSION,
        runId,
        startedAt: startedAt.toISOString(),
        finishedAt: startedAt.toISOString(),
        ats: null,
        requestedUrl: redactUrl(input.applicationUrl),
        finalUrl: null,
        outcome: 'failed',
        reasons: [],
        fields: [],
        summary: {
            total: 0,
            filledVerified: 0,
            unansweredOptional: 0,
            unresolvedRequired: 0,
            unresolvedUncertain: 0,
            unverified: 0,
            manualAction: 0,
            errors: 0,
        },
        resume: {
            sha256: resume.sha256,
            bytes: resume.bytes.length,
            fieldId: null,
            uploaded: false,
            uploadRequestAllowed: false,
            inputHeldChosenResume: false,
            verification: 'none',
            verified: false,
            observedFilename: null,
            parser: {
                observable: false,
                requestAllowed: false,
                status: 'not_attempted',
                detail: 'no resume was attached',
                waitedMs: 0,
                atPreparation: 'not_attempted',
            },
            identity: {
                logicalKey: null,
                tracking: 'not_found',
                note: 'no resume upload field was recognised',
            },
        },
        controls: [],
        blockers: {
            captcha: 'none',
            captchaDetails: [],
            authentication: false,
            multistep: false,
        },
        submission: {
            assertion: 'worker_did_not_submit',
            blockedAttempts: 0,
            suspected: false,
        },
        safetyEvents: [],
        screenshots: [],
        warnings: [],
        diagnostics: { durationMs: 0, headless, held: false, hold: null },
        finalState: null,
    };
    const finish = async (
        outcome: Outcome,
        reasons: string[],
    ): Promise<PrepareResult> => {
        report.outcome = outcome;
        report.reasons = reasons;
        report.finishedAt = new Date().toISOString();
        report.diagnostics.durationMs = Date.now() - startedAt.getTime();
        return {
            report,
            reportPath: await writeReport(runDir, report),
            runDir,
        };
    };

    let url: URL;
    try {
        url = assertAllowedUrl(input.applicationUrl, {
            allowLocalFixture: options.allowLocalFixture,
        });
    } catch (e) {
        return finish('unsupported', [(e as Error).message]);
    }
    const ats = options.allowLocalFixture
        ? (options.ats ?? null)
        : atsForHost(url.hostname);
    if (ats === null)
        return finish('unsupported', [
            'no ATS adapter for this URL (local fixtures must pass `ats`)',
        ]);
    report.ats = ats;
    const adapter = adapterFor(ats);
    report.resume.parser.observable = adapter.resumeParser !== undefined;
    const formUrl = adapter.formUrl(url);

    const resumeContext = {
        sha256: resume.sha256,
        bytes: resume.bytes.length,
        filename: basename(input.resumePdfPath),
    };
    const guard = new SubmissionGuard(
        formUrl,
        options.uploadExceptions ??
            adapter.uploadExceptions(formUrl.origin, resumeContext),
        options.authorizationRules ??
            adapter.authorizationRules?.(formUrl.origin) ??
            [],
    );
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

        await page.goto(formUrl.toString(), {
            waitUntil: 'domcontentloaded',
            timeout: 45_000,
        });
        // Locked immediately: the only main-frame navigation this run ever allows is the initial one
        // (including its redirects). Anything a script does afterwards is a safety event.
        guard.lockNavigation();
        await page
            .waitForLoadState('networkidle', { timeout: 15_000 })
            .catch(() => report.warnings.push('networkidle_timeout'));
        await page.waitForTimeout(1_500); // let SPA forms render

        await capture(page, runDir, report, 'before');

        let discovery = await discoverForm(page);
        const resumeField =
            discovery.fields.find(
                (f) =>
                    f.kind === 'file' &&
                    classify(f, input).mappedTo === 'resume',
            ) ?? null;

        // The resume goes first: some forms parse it and overwrite other fields.
        let located: Located | null = null;
        if (resumeField !== null) {
            report.resume.fieldId = resumeField.id;
            report.resume.identity = {
                logicalKey: resumeField.key,
                tracking: 'same_element',
                note: 'the same element is still present',
            };
            guard.openUploadWindow(); // exceptions marked duringUploadOnly may fire only inside this window
            try {
                await uploadResumeFile(
                    page,
                    resumeField.id,
                    input.resumePdfPath,
                );
                report.resume.uploaded = true;
                // Right after selection, before any widget can remove the input: does the browser
                // hold exactly the chosen resume? This is the same File the widget goes on to upload,
                // and the only content check available when the guard cannot see file bytes.
                const held = await observeFileInput(
                    page,
                    resumeField.id,
                    basename(input.resumePdfPath),
                ).catch(() => null);
                report.resume.inputHeldChosenResume =
                    held !== null && held.sha256 === resume.sha256;
            } catch (e) {
                report.warnings.push(`resume upload failed: ${firstLine(e)}`);
            }
            await page.waitForTimeout(1_000);
            guard.closeUploadWindow();
            // Wait (bounded) for the parser's visible terminal state BEFORE reading the form: a parse
            // can autofill fields, and the later fill/verify must see the settled page. Success is
            // claimed only from a visible indicator, never from the request or the file hash.
            await refreshParser(
                page,
                report,
                guard,
                adapter,
                options.parserWaitMs ?? 15_000,
            );
            report.resume.parser.atPreparation = report.resume.parser.status;
            // An upload can make a widget remove or replace its input. Re-discover, then
            // track the resume control by identity, never by its old position or id alone.
            const previousIds = new Set(discovery.fields.map((f) => f.id));
            discovery = await discoverForm(page);
            located = locateField(resumeField, discovery.fields, previousIds);
            report.resume.identity = {
                logicalKey: resumeField.key,
                tracking: located.tracking,
                note: located.note,
            };
            if (located.field) report.resume.fieldId = located.field.id;
        }
        report.warnings.push(...discovery.warnings);

        report.fields = await processFields(
            page,
            input,
            discovery,
            resumeField === null ? null : (located?.field?.id ?? null),
            report.warnings,
        );
        if (
            resumeField !== null &&
            located !== null &&
            (located.tracking === 'removed' || located.tracking === 'ambiguous')
        ) {
            // Keep the resume's own result, attributed to its own logical identity, instead of
            // letting a failure land on whichever control now sits where it used to be.
            const own: FieldResult = {
                ...resumeField,
                sensitive: false,
                classification: 'known',
                mappedTo: 'resume',
                status: 'error',
                presence:
                    located.tracking === 'removed'
                        ? 'removed_after_upload'
                        : 'ambiguous_after_upload',
                note: `resume control ${located.tracking === 'removed' ? 'removed from the page after the upload' : 'cannot be told apart from another field after the upload'}: ${located.note}`,
            };
            const at = report.fields.findIndex(
                (f) => f.position > own.position,
            );
            report.fields.splice(at < 0 ? report.fields.length : at, 0, own);
        }
        await verifyResume(
            page,
            report,
            resume.sha256,
            input.resumePdfPath,
            adapter.resumeUpload !== undefined &&
                guard.exceptionUses(adapter.resumeUpload.exceptionName) > 0,
            adapter.resumeUpload !== undefined,
        );

        // Re-read every filled field after all the others were filled and the page settled:
        // a widget that clears or replaces a value when focus moves on is caught here.
        await page.waitForTimeout(800);
        await reverifyFields(page, report);

        report.controls = discovery.controls;
        report.blockers = {
            captcha: discovery.captcha.state,
            captchaDetails: discovery.captcha.details,
            authentication: discovery.passwordField,
            multistep: discovery.controls.some(
                (c) => c.role === 'navigation' && !c.hidden,
            ),
        };
        if (discovery.captcha.state === 'inactive_markup')
            report.warnings.push(
                'captcha markup present but inactive (hidden/zero-size/off-screen/invisible); nothing was attempted',
            );

        await page.waitForTimeout(500); // let any blocked request register
        const signals = await readPageSignals(page);
        report.finalUrl = redactUrl(signals.url);
        if (signals.confirmationText)
            guard.record(
                'suspected_submission',
                `confirmation-style text on page: "${signals.confirmationText}"`,
            );
        else if (
            !signals.url.startsWith('chrome-error:') &&
            new URL(signals.url).pathname !== formUrl.pathname
        ) {
            guard.record(
                'suspected_submission',
                `page path changed from ${formUrl.pathname} to ${new URL(signals.url).pathname}`,
            );
        }
        await capture(page, runDir, report, 'after_fill');

        syncSafety(report, guard);
        const decision = decide(report);
        const result = await finish(decision.outcome, decision.reasons);

        if (options.hold) {
            report.diagnostics.held = true;
            guard.phase = 'human_hold';
            const stopFile = stopFilePath(runDir);
            process.stderr.write(
                `\nHOLD: run ${runId} outcome=${report.outcome}. Review the form in the browser window.\n` +
                    `The guard stays active: submitting from this window is blocked by design.\n` +
                    `End the hold by: pressing Enter here, closing the window, or creating the file\n` +
                    `  ${stopFile}\n` +
                    `(the file only ends the hold; its contents are never read).\n\n`,
            );
            const heldAt = Date.now();
            const endedBy: HoldEnd =
                (await (options.holdUntil
                    ? options.holdUntil(page, browser, { stopFile })
                    : waitForHoldEnd(page, browser, { stopFile }))) ??
                'callback';
            report.diagnostics.hold = {
                endedBy,
                durationMs: Date.now() - heldAt,
            };
            await finalizeHold(
                page,
                runDir,
                report,
                guard,
                adapter,
                options.finishParserWaitMs ?? 5_000,
            );
            // Earlier safety events are never replaced: the report always carries the guard's full log.
            syncSafety(report, guard);
            const final = decide(report);
            return finish(final.outcome, final.reasons);
        }
        return result;
    } catch (e) {
        syncSafety(report, guard);
        return finish(guard.hasFailure() ? 'safety_failure' : 'failed', [
            firstLine(e),
        ]);
    } finally {
        await browser.close().catch(() => {});
    }
}

async function processFields(
    page: Page,
    input: PrepareInput,
    discovery: Discovery,
    resumeFieldId: string | null,
    warnings: string[],
): Promise<FieldResult[]> {
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
        if (c.mappedTo === 'resume') {
            // A second file input that also looks like a resume: the resume went to the tracked
            // control, so this one is left alone and never mistaken for it.
            result.status = required
                ? 'unresolved_uncertain'
                : 'unanswered_optional';
            result.note =
                'another file input that looks like a resume; the resume was attached to a different control';
            continue;
        }
        if (c.classification !== 'known') {
            if (c.classification === 'uncertain')
                result.status = required
                    ? 'unresolved_uncertain'
                    : 'unanswered_optional';
            else
                result.status = required
                    ? 'unresolved_required'
                    : 'unanswered_optional';
            continue;
        }

        try {
            if (
                (field.kind === 'text' || field.kind === 'textarea') &&
                c.value !== undefined
            ) {
                // Before typing anything: is this a typeahead/combobox that needs a suggestion
                // chosen? The worker has no click, and unselected text is discarded by such
                // widgets, so it is left alone and handed to a person with the value intact.
                const pre = await readControlState(page, field.id);
                if (pre.requiresSelection) {
                    result.status = 'manual_action_required';
                    result.expected = c.value;
                    result.note = `typeahead/combobox: a suggestion must be selected by a person, so the value was not typed (${pre.selectionSignals.join(', ')})`;
                    result.control = controlEvidence(c.value, pre);
                    if (!required)
                        warnings.push(
                            `optional field "${field.label ?? field.name ?? field.id}" needs a person to select a suggestion; it was not typed`,
                        );
                    continue;
                }
                await fillText(page, field.id, c.value);
                // The evidence is the user-visible control, never a hidden input alone.
                const state = await readControlState(page, field.id);
                const verdict = judgeText(c.value, state);
                result.expected = c.value;
                result.actual = state.visibleValue;
                result.status = verdict.status;
                if (verdict.note) result.note = verdict.note;
                result.control = controlEvidence(c.value, state);
            } else if (field.kind === 'select' && c.value !== undefined) {
                await selectOption(page, field.id, c.value);
                const actual = await readSelectedOption(page, field.id);
                result.expected = c.value;
                result.actual = actual ?? '';
                result.status =
                    actual === c.value ? 'filled_verified' : 'error';
            } else if (
                field.kind === 'radio' &&
                c.optionIndex !== undefined &&
                c.value !== undefined
            ) {
                await chooseRadio(page, field.id, c.optionIndex);
                const checked = await readCheckedRadio(page, field.id);
                result.expected = c.value;
                result.actual =
                    checked >= 0 ? (field.options?.[checked] ?? '') : '';
                result.status =
                    checked === c.optionIndex ? 'filled_verified' : 'error';
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

function controlEvidence(
    expected: string,
    state: ControlState,
): NonNullable<FieldResult['control']> {
    return {
        visible: state.visible,
        composite: state.composite,
        signals: state.signals,
        hiddenValueMatches:
            state.hiddenValues.length > 0
                ? state.hiddenValues.includes(expected)
                : null,
    };
}

/**
 * Final pass over fields that were filled. A change since the immediate
 * read-back (widget reset, replaced, hidden) downgrades the field.
 */
async function reverifyFields(
    page: Page,
    report: PrepareReport,
): Promise<void> {
    for (const f of report.fields) {
        if (f.expected === undefined || f.id === report.resume.fieldId)
            continue;
        if (f.status !== 'filled_verified' && f.status !== 'unverified')
            continue;
        try {
            if (f.kind === 'text' || f.kind === 'textarea') {
                const state = await readControlState(page, f.id);
                const before: TextVerdict =
                    f.status === 'unverified'
                        ? { status: 'unverified', note: f.note ?? '' }
                        : { status: 'filled_verified' };
                const now = judgeText(f.expected, state);
                const combined = worse(before, now);
                if (now.status !== 'filled_verified' && combined === now) {
                    f.status = now.status;
                    f.note = `final check after the other fields were filled: ${now.note}`;
                }
                f.actual = state.visibleValue;
                f.control = controlEvidence(f.expected, state);
            } else if (f.kind === 'select') {
                const actual = await readSelectedOption(page, f.id);
                f.actual = actual ?? '';
                if (actual !== f.expected) {
                    f.status = 'error';
                    f.note = 'final check: selected option changed after fill';
                }
            } else if (f.kind === 'radio') {
                const checked = await readCheckedRadio(page, f.id);
                const actual = checked >= 0 ? (f.options?.[checked] ?? '') : '';
                f.actual = actual;
                if (actual !== f.expected) {
                    f.status = 'error';
                    f.note = 'final check: chosen option changed after fill';
                }
            }
        } catch (e) {
            f.status = 'error';
            f.note = `final check failed: ${firstLine(e)}`;
        }
        if (f.status === 'unverified' && f.required !== true) {
            report.warnings.push(
                `optional field "${f.label ?? f.name ?? f.id}" is unverified: ${f.note ?? ''}`,
            );
        }
    }
}

/** Re-observes the resume parser (bounded) and stores the result on the report. */
async function refreshParser(
    page: Page,
    report: PrepareReport,
    guard: SubmissionGuard,
    adapter: ReturnType<typeof adapterFor>,
    timeoutMs: number,
): Promise<void> {
    const probe = adapter.resumeParser;
    const parser = report.resume.parser;
    const requestAllowed = () =>
        probe !== undefined && guard.exceptionUses(probe.exceptionName) > 0;
    parser.requestAllowed = requestAllowed();
    if (probe === undefined) {
        parser.status = 'not_observable';
        parser.detail = 'no parser status indicator is known for this ATS';
        parser.waitedMs = 0;
        return;
    }
    const observed = await observeParser(page, probe, {
        requestAllowed,
        attached: report.resume.uploaded,
        timeoutMs,
    });
    parser.requestAllowed = requestAllowed();
    parser.status = observed.status;
    parser.detail = observed.detail;
    parser.waitedMs = observed.waitedMs;
    if (parser.status === 'failure' || parser.status === 'unknown') {
        const warning = `resume parser: ${parser.status} (${parser.detail})`;
        if (!report.warnings.includes(warning)) report.warnings.push(warning);
    }
}

/**
 * Graceful end of a hold: re-observe the parser (bounded), capture the final
 * viewport screenshots and the form's current displayed values. Everything
 * is additive: statuses decided during preparation, and the guard's events,
 * are left exactly as they were.
 */
async function finalizeHold(
    page: Page,
    runDir: string,
    report: PrepareReport,
    guard: SubmissionGuard,
    adapter: ReturnType<typeof adapterFor>,
    parserWaitMs: number,
): Promise<void> {
    if (page.isClosed()) {
        report.warnings.push(
            'the browser window was closed before the final capture; no end-of-hold evidence',
        );
        return;
    }
    try {
        await refreshParser(page, report, guard, adapter, parserWaitMs);
        await capture(page, runDir, report, 'end_of_hold');
        const fieldValues: FinalState['fieldValues'] = [];
        for (const f of report.fields) {
            if (f.sensitive || f.kind === 'file' || f.kind === 'checkbox')
                continue;
            fieldValues.push({
                id: f.id,
                label: f.label,
                kind: f.kind,
                statusAtPreparation: f.status,
                displayed: await readDisplayedValue(page, f),
            });
        }
        report.finalState = {
            capturedAt: new Date().toISOString(),
            url: redactUrl(page.url()),
            fieldValues,
        };
    } catch (e) {
        report.warnings.push(`final capture failed: ${firstLine(e)}`);
    }
}

async function verifyResume(
    page: Page,
    report: PrepareReport,
    expectedSha: string,
    path: string,
    uploadRequestAllowed: boolean,
    uploadNeedsConfirmation: boolean,
): Promise<void> {
    const id = report.resume.fieldId;
    if (id === null) return;
    report.resume.uploadRequestAllowed = uploadRequestAllowed;
    const expectedName = basename(path);
    const field = report.fields.find((f) => f.id === id);
    const obs = await observeFileInput(page, id, expectedName).catch(
        () => null,
    );
    if (obs === null || !report.resume.uploaded) {
        report.resume.verification = 'none';
    } else if (obs.sha256 === expectedSha) {
        // Where attaching is a separate upload step (Greenhouse), a populated input is not an
        // attachment: the upload request must have been allowed and the page must show the file name.
        const confirmed =
            !uploadNeedsConfirmation ||
            (uploadRequestAllowed && obs.nameInPageText);
        report.resume.verification = confirmed
            ? 'content_sha256'
            : 'input_only';
        report.resume.verified = confirmed;
    } else if (
        obs.sha256 === null &&
        obs.name === expectedName &&
        obs.size === report.resume.bytes
    ) {
        const confirmed =
            !uploadNeedsConfirmation ||
            (uploadRequestAllowed && obs.nameInPageText);
        report.resume.verification = confirmed ? 'name_size' : 'input_only';
        report.resume.verified = confirmed;
    } else if (
        obs.name === null &&
        uploadRequestAllowed &&
        report.resume.inputHeldChosenResume &&
        obs.nameInPageText
    ) {
        // The widget took the file and removed its input (Greenhouse). Three independent facts:
        // the input held exactly the chosen resume when the upload was triggered (SHA-256 of the
        // browser's own copy), the guard allowed the one validated upload request to the destination
        // the page's own presign response named, and the page now shows the resume's file name.
        // The guard cannot see the file's bytes in transit, so this is not a content hash of the upload.
        report.resume.verification = 'upload_request';
        report.resume.verified = true;
    } else if (obs.name === null && obs.nameInPageText) {
        report.resume.verification = 'page_text';
    }
    report.resume.observedFilename = obs?.name ?? null;
    if (field) {
        field.expected = expectedName;
        field.actual = obs?.name ?? '';
        field.status = report.resume.verified ? 'filled_verified' : 'error';
        if (!report.resume.verified)
            field.note =
                `${field.presence ? `${field.note ?? ''} ` : ''}resume not verified (${report.resume.verification})`.trim();
    }
}

/** Pure: exported for tests. Priority: safety > unsupported > blocked > input_required > ready. */
export function decide(report: PrepareReport): {
    outcome: Outcome;
    reasons: string[];
} {
    const failures = report.safetyEvents.filter((e) =>
        SAFETY_FAILURE_KINDS.has(e.kind),
    );
    if (failures.length > 0) {
        return {
            outcome: 'safety_failure',
            reasons: failures.map((f) => `${f.kind}: ${f.detail}`),
        };
    }
    if (report.fields.length === 0)
        return {
            outcome: 'unsupported',
            reasons: ['no form fields were found'],
        };

    const blocked: string[] = [];
    if (report.blockers.captcha === 'challenge_visible')
        blocked.push('a visible captcha challenge needs a human');
    else if (report.blockers.captcha === 'uncertain')
        blocked.push(
            'captcha state is uncertain (displayed markup that could not be classified); check the page by eye',
        );
    if (report.blockers.authentication)
        blocked.push('a login/password field is present');
    if (report.blockers.multistep)
        blocked.push(
            'the form has Next/Continue steps; multistep forms are not handled',
        );
    if (report.resume.fieldId === null)
        blocked.push('no resume upload field was recognised');
    else if (!report.resume.verified)
        blocked.push(
            `resume upload could not be verified (${report.resume.verification})`,
        );
    if (blocked.length > 0) return { outcome: 'blocked', reasons: blocked };

    const input = report.fields.filter(
        (f) =>
            f.status === 'unresolved_required' ||
            f.status === 'unresolved_uncertain' ||
            // A required value we could not confirm a person would see is not done.
            (f.status === 'unverified' && f.required === true) ||
            // A required typeahead only a person can complete is not done either.
            (f.status === 'manual_action_required' && f.required === true) ||
            f.status === 'error',
    );
    if (input.length > 0) {
        return {
            outcome: 'input_required',
            reasons: input.map(
                (f) => `${f.status}: ${f.label ?? f.name ?? f.id}`,
            ),
        };
    }
    return { outcome: 'ready_for_review', reasons: [] };
}

function syncSafety(report: PrepareReport, guard: SubmissionGuard): void {
    report.safetyEvents = [...guard.events];
    report.submission.blockedAttempts = guard.events.filter(
        (e) =>
            SAFETY_FAILURE_KINDS.has(e.kind) &&
            e.kind !== 'suspected_submission',
    ).length;
    report.submission.suspected = guard.events.some(
        (e) => e.kind === 'suspected_submission',
    );
    const s = report.summary;
    s.total = report.fields.length;
    s.filledVerified = report.fields.filter(
        (f) => f.status === 'filled_verified',
    ).length;
    s.unansweredOptional = report.fields.filter(
        (f) => f.status === 'unanswered_optional',
    ).length;
    s.unresolvedRequired = report.fields.filter(
        (f) => f.status === 'unresolved_required',
    ).length;
    s.unresolvedUncertain = report.fields.filter(
        (f) => f.status === 'unresolved_uncertain',
    ).length;
    s.unverified = report.fields.filter(
        (f) => f.status === 'unverified',
    ).length;
    s.manualAction = report.fields.filter(
        (f) => f.status === 'manual_action_required',
    ).length;
    s.errors = report.fields.filter((f) => f.status === 'error').length;
}

async function loadResume(
    path: string,
): Promise<{ bytes: Buffer; sha256: string }> {
    const bytes = await readFile(path);
    if (bytes.length === 0 || bytes.length > MAX_RESUME_BYTES)
        throw new Error('resume PDF is empty or larger than 10 MB');
    if (bytes.subarray(0, 5).toString('latin1') !== '%PDF-')
        throw new Error('resume file is not a PDF');
    return { bytes, sha256: createHash('sha256').update(bytes).digest('hex') };
}

async function capture(
    page: Page,
    dir: string,
    report: PrepareReport,
    phase: ScreenshotPhase,
): Promise<void> {
    const { records, warnings } = await captureViewports(page, dir, phase);
    report.screenshots.push(...records);
    report.warnings.push(...warnings);
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
