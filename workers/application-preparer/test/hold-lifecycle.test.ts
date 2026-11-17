// Graceful end of a hold through the real engine, as a non-interactive
// supervisor would drive it: start a held run, wait for preparation, drop the
// STOP file, and collect the final report. Chromium must be gone afterwards.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { PrepareReport } from '../src/contracts.ts';
import { startServer, type TestServer } from './support/server.ts';
import {
    LEVER_AUTH_QUESTION,
    childProcessCount,
    fixtureHtml,
    startHeld,
} from './support/run.ts';

let server: TestServer;
before(async () => void (server = await startServer()));
after(async () => server.close());

const ANSWERS = { [LEVER_AUTH_QUESTION]: 'Yes' };
const settleChildren = async () => {
    for (let i = 0; i < 30 && childProcessCount() > 0; i++)
        await new Promise((r) => setTimeout(r, 100));
    return childProcessCount();
};

describe('graceful end of a hold by the STOP file', () => {
    it('ends the hold, captures final evidence and state, writes the report, and closes Chromium', async () => {
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
        });
        const atHold = await held.waitForPreparation();
        assert.equal(
            atHold.diagnostics.hold,
            null,
            'preparation report: hold not ended yet',
        );
        assert.equal(
            atHold.screenshots.some((s) => s.phase === 'end_of_hold'),
            false,
        );
        assert.ok(
            childProcessCount() >= 1,
            'Chromium is running during the hold',
        );

        await held.stop();
        const { report, runDir } = await held.promise;

        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
        assert.equal(report.diagnostics.held, true);
        assert.equal(report.diagnostics.hold?.endedBy, 'stop_file');
        assert.ok((report.diagnostics.hold?.durationMs ?? -1) >= 0);

        const final = report.screenshots.filter(
            (s) => s.phase === 'end_of_hold',
        );
        assert.ok(
            final.length >= 1,
            'final viewport screenshots were captured',
        );
        for (const s of final)
            assert.ok(existsSync(join(runDir, s.file)), s.file);
        assert.ok(
            final.every((s, i) => s.index === i + 1 && s.of === final.length),
        );
        assert.equal(
            report.warnings.some((w) =>
                /final capture failed|closed before/.test(w),
            ),
            false,
        );

        assert.ok(report.finalState, 'the final form state was captured');
        const displayed = (name: string) =>
            report.finalState!.fieldValues.find((f) => f.label === name)
                ?.displayed;
        assert.equal(displayed('Full name'), 'Alex Example');
        assert.equal(displayed('Email'), 'alex.example@example.test');
        assert.equal(
            displayed('Current location'),
            '',
            'the typeahead was left empty',
        );
        assert.equal(
            report.finalState!.fieldValues.find(
                (f) => f.label === 'Current location',
            )?.statusAtPreparation,
            'manual_action_required',
        );
        assert.ok(
            !report.finalState!.fieldValues.some(
                (f) => report.fields.find((x) => x.id === f.id)?.sensitive,
            ),
            'sensitive fields are not recorded',
        );
        assert.equal(report.resume.parser.status, 'success');

        const onDisk = JSON.parse(
            await readFile(join(runDir, 'report.json'), 'utf8'),
        ) as PrepareReport;
        assert.equal(
            onDisk.diagnostics.hold?.endedBy,
            'stop_file',
            'the report on disk is the final one',
        );
        assert.equal(
            await settleChildren(),
            0,
            'Chromium was shut down by the worker',
        );
        assert.deepEqual(server.unexpected(), []);
    });

    it('a STOP file carrying instructions is only a stop: no clicks, no guard change, no submission', async () => {
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
        });
        await held.waitForPreparation();
        await held.stop(
            JSON.stringify({
                action: 'click',
                selector: '#btn-submit',
                guard: 'off',
                allow: ['POST /example-company/0000/apply'],
            }),
        );
        const { report } = await held.promise;
        assert.equal(report.diagnostics.hold?.endedBy, 'stop_file');
        assert.equal(report.outcome, 'ready_for_review');
        assert.equal(
            report.safetyEvents.some((e) => e.kind === 'submit_event_blocked'),
            false,
            'nothing clicked Submit',
        );
        assert.deepEqual(
            report.safetyEvents.map((e) => e.kind),
            ['allowed_exception_used'],
        );
        assert.deepEqual(
            server.mutations,
            ['POST /parseResume'],
            'only the allowed resume parse ever reached the server',
        );
        assert.equal(await settleChildren(), 0);
    });

    it('a STOP file created before the hold starts still only ends the hold; preparation and the guard are unaffected', async () => {
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
        });
        await held.stop(); // before preparation even finishes
        const { report } = await held.promise;
        assert.equal(report.diagnostics.hold?.endedBy, 'stop_file');
        assert.equal(
            report.fields.find((f) => f.name === 'name')?.status,
            'filled_verified',
            'preparation ran fully',
        );
        assert.equal(report.resume.verified, true);
        assert.deepEqual(server.unexpected(), []);
        assert.equal(await settleChildren(), 0);
    });
});

describe('shutdown never conceals or overwrites safety events', () => {
    it('events from preparation are kept verbatim; hold-phase events are appended', async () => {
        // The page keeps POSTing to a same-origin endpoint: each attempt is blocked and recorded.
        const html = (await fixtureHtml('lever-form.html')).replace(
            '</body>',
            `<script>setInterval(() => fetch('/beacon-like', { method: 'POST', body: 'x' }).catch(() => {}), 400)</script></body>`,
        );
        server.add('/post-forever.html', html);
        const held = await startHeld(server, '/post-forever.html', 'lever', {
            input: { answers: ANSWERS },
        });
        const atHold = await held.waitForPreparation();
        assert.ok(
            atHold.safetyEvents.length >= 1,
            'preparation already recorded blocked requests',
        );
        await new Promise((r) => setTimeout(r, 1500)); // let some hold-phase attempts happen
        await held.stop();
        const { report } = await held.promise;

        const prefix = report.safetyEvents.slice(0, atHold.safetyEvents.length);
        assert.deepEqual(
            prefix,
            atHold.safetyEvents,
            'earlier events are byte-for-byte unchanged and still first',
        );
        assert.ok(
            report.safetyEvents.length > atHold.safetyEvents.length,
            'later events were appended',
        );
        assert.ok(report.safetyEvents.some((e) => e.phase === 'human_hold'));
        assert.ok(
            report.safetyEvents.every(
                (e, i) =>
                    i >= atHold.safetyEvents.length ||
                    e.phase === 'preparation',
            ),
        );
        assert.equal(report.outcome, 'safety_failure');
        assert.deepEqual(server.unexpected(), []);
        assert.equal(await settleChildren(), 0);
    });
});

describe('final capture reflects what a person did, without rewriting what preparation decided', () => {
    it('shows a typeahead a person completed during the hold, while its preparation status stays manual_action_required', async () => {
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
            options: {
                holdUntil: async (page) => {
                    // The "person": choose a suggestion (hidden value) and keep the text.
                    await page.evaluate(() => {
                        (
                            document.getElementById(
                                'selected-location',
                            ) as HTMLInputElement
                        ).value = 'place-1';
                    });
                    await page
                        .locator('#location-input')
                        .fill('Springfield, Exampleland');
                    await page
                        .locator('input[name="name"]')
                        .fill('Alex Example');
                    return 'callback';
                },
            },
        });
        const { report } = await held.promise;
        const f = report.finalState!.fieldValues.find(
            (x) => x.label === 'Current location',
        )!;
        assert.equal(f.displayed, 'Springfield, Exampleland');
        assert.equal(f.statusAtPreparation, 'manual_action_required');
        assert.equal(
            report.fields.find((x) => x.name === 'location')?.status,
            'manual_action_required',
            'preparation status untouched',
        );
        assert.equal(report.diagnostics.hold?.endedBy, 'callback');
        assert.equal(report.outcome, 'ready_for_review');
    });

    it('if the window was already closed, it says so, captures nothing it cannot, and keeps all earlier evidence', async () => {
        const held = await startHeld(server, '/lever-form.html', 'lever', {
            input: { answers: ANSWERS },
            options: {
                holdUntil: async (page) => {
                    await page.close();
                    return 'window_closed';
                },
            },
        });
        const { report } = await held.promise;
        assert.equal(report.diagnostics.hold?.endedBy, 'window_closed');
        assert.equal(report.finalState, null);
        assert.equal(
            report.screenshots.some((s) => s.phase === 'end_of_hold'),
            false,
        );
        assert.ok(
            report.screenshots.some((s) => s.phase === 'after_fill'),
            'preparation evidence is intact',
        );
        assert.ok(
            report.warnings.some((w) =>
                /closed before the final capture/.test(w),
            ),
        );
        assert.equal(report.outcome, 'ready_for_review');
    });
});
