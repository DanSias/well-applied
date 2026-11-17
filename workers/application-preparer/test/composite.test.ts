// Composite / autocomplete / hidden-input regression fixtures. They are
// SYNTHETIC variants of the Lever-style fixture's location field and make no
// claim about how Lever actually implements it: the first live run did not
// capture that DOM. They exercise the verification rules, not Lever.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, type TestServer } from './support/server.ts';
import { fixtureHtml, LEVER_AUTH_QUESTION, runOn } from './support/run.ts';

let server: TestServer;
let base: string;
const ANSWERS = { [LEVER_AUTH_QUESTION]: 'Yes' };
const LOCATION_LI =
    /<li class="application-question">\s*<label\s*>\s*<div class="application-label">\s*Current location\s*<\/div>[\s\S]*?<\/li>/;

before(async () => {
    server = await startServer();
    base = await fixtureHtml('lever-form.html');
});
after(async () => server.close());

function location(path: string, markup: string, script = ''): string {
    const html = base
        .replace(LOCATION_LI, `<li class="application-question">${markup}</li>`)
        .replace('</body>', `${script}</body>`);
    assert.notEqual(html, base);
    server.add(path, html);
    return path;
}
const field = (r: Awaited<ReturnType<typeof runOn>>, name: string) =>
    r.report.fields.find((f) => f.name === name || f.label === name)!;
const label = (text: string, required = false) =>
    `<div class="application-label">${text}${required ? ' <span class="required">✱</span>' : ''}</div>`;
const run = (path: string, options = {}) =>
    runOn(server, path, 'lever', {
        input: { answers: ANSWERS },
        options,
    });

describe('composite and hidden-input location fields', () => {
    it('ordinary input is still verified straightforwardly', async () => {
        const r = await run('/lever-form.html');
        const f = field(r, 'location');
        assert.equal(f.status, 'filled_verified');
        assert.equal(f.control?.composite, false);
        assert.equal(f.control?.visible, true);
    });

    it('an unrelated hidden input beside the control does not make it composite', async () => {
        const path = location(
            '/loc-unrelated-hidden.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" name="location"><input type="hidden" name="csrf_token" value="abc"></div></label>`,
        );
        const f = field(await run(path), 'location');
        assert.equal(f.status, 'filled_verified');
        assert.equal(f.control?.composite, false);
    });

    it('ARIA combobox with no confirmable committed value is unverified (optional: warns, does not block)', async () => {
        const path = location(
            '/loc-combobox.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" name="location" role="combobox" aria-autocomplete="list" aria-expanded="false"></div></label>`,
        );
        const r = await run(path);
        const f = field(r, 'location');
        assert.equal(f.status, 'unverified');
        assert.equal(
            f.actual,
            'Springfield, Exampleland',
            'the displayed text is what was read',
        );
        assert.ok(f.control?.signals.includes('role_combobox'));
        assert.equal(r.report.summary.unverified, 1);
        assert.ok(
            r.report.warnings.some((w) =>
                /optional field "Current location" is unverified/.test(w),
            ),
        );
        assert.equal(
            r.report.outcome,
            'ready_for_review',
            'optional, so it does not block; the report says so plainly',
        );
    });

    it('a REQUIRED composite field that cannot be confirmed makes the run input_required', async () => {
        const path = location(
            '/loc-combobox-required.html',
            `<label>${label('Current location', true)}<div class="application-field"><input type="text" name="location" role="combobox" aria-autocomplete="list" required></div></label>`,
        );
        const r = await run(path);
        assert.equal(field(r, 'location').status, 'unverified');
        assert.equal(r.report.outcome, 'input_required');
        assert.match(
            r.report.reasons.join(' '),
            /unverified: Current location/,
        );
    });

    it('visible text plus a related hidden input that stays empty is unverified', async () => {
        const path = location(
            '/loc-hidden-empty.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" name="location-search" autocomplete="off"><input type="hidden" name="location" value=""></div></label>`,
        );
        const f = field(await run(path), 'location-search');
        assert.equal(f.status, 'unverified');
        assert.equal(f.control?.hiddenValueMatches, false);
        assert.match(f.note!, /does not match/);
    });

    it('visible text and a related hidden input that agree is verified', async () => {
        const path = location(
            '/loc-hidden-mirrors.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location-search" autocomplete="off"><input type="hidden" id="locv" name="location" value=""></div></label>`,
            `<script>document.getElementById('locq').addEventListener('input', (e) => { document.getElementById('locv').value = e.target.value; })</script>`,
        );
        const f = field(await run(path), 'location-search');
        assert.equal(f.status, 'filled_verified');
        assert.equal(f.control?.hiddenValueMatches, true);
        assert.equal(f.control?.composite, true);
    });

    it('a hidden input holding the value never makes up for an empty display', async () => {
        const path = location(
            '/loc-hidden-only.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location-search"><input type="hidden" id="locv" name="location" value=""></div></label>`,
            // Hidden receives the value, then the widget blanks what is displayed.
            `<script>const q = document.getElementById('locq'); q.addEventListener('input', () => { document.getElementById('locv').value = q.value; setTimeout(() => { q.value = ''; }, 30); });</script>`,
        );
        const r = await run(path);
        const f = field(r, 'location-search');
        assert.equal(f.status, 'error');
        assert.equal(f.actual, '');
        assert.equal(
            f.control?.hiddenValueMatches,
            true,
            'the hidden input does hold the value; that must not matter',
        );
        assert.equal(r.report.outcome, 'input_required');
    });

    it('a widget that clears the text when focus moves to later fields is caught by the final check', async () => {
        const path = location(
            '/loc-clears-on-blur.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location"></div></label>`,
            `<script>document.getElementById('locq').addEventListener('blur', (e) => { e.target.value = ''; })</script>`,
        );
        const r = await run(path);
        const f = field(r, 'location');
        assert.equal(
            f.status,
            'error',
            'immediate read-back passed; the later pass must not',
        );
        assert.match(f.note!, /final check after the other fields were filled/);
        assert.equal(f.actual, '');
        assert.equal(r.report.outcome, 'input_required');
    });

    it('a control that stops being visible is unverified, not verified by its leftover value', async () => {
        const path = location(
            '/loc-vanishes.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location"></div></label>`,
            `<script>document.getElementById('locq').addEventListener('input', (e) => { setTimeout(() => { e.target.style.visibility = 'hidden'; }, 30); })</script>`,
        );
        const r = await run(path);
        const f = field(r, 'location');
        assert.equal(f.status, 'unverified');
        assert.match(f.note!, /not visible/);
        assert.equal(f.control?.visible, false);
    });
});
