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

// ---------------------------------------------------------------------------
// Typeaheads that need a suggestion chosen: skipped BEFORE typing.
// ---------------------------------------------------------------------------
const PROBE = `<script>document.getElementById('locq').addEventListener('input', () => fetch('/typed-probe').catch(() => {}))</script>`;
const typed = () => server.hits.includes('/typed-probe');
const EXPECTED = 'Springfield, Exampleland';

describe('typeahead / combobox controls are not typed into', () => {
    it('ARIA combobox: skipped before filling, value kept for review, optional so not input_required', async () => {
        server.hits.length = 0;
        const path = location(
            '/ta-combobox.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location" role="combobox" aria-autocomplete="list" aria-expanded="false"></div></label>`,
            PROBE,
        );
        const r = await run(path);
        const f = field(r, 'location');
        assert.equal(f.status, 'manual_action_required');
        assert.equal(
            f.expected,
            EXPECTED,
            'the intended value stays available for manual review',
        );
        assert.equal(
            f.actual,
            undefined,
            'nothing was read back because nothing was typed',
        );
        assert.match(f.note!, /suggestion must be selected by a person/);
        assert.match(f.note!, /role_combobox/);
        assert.equal(typed(), false, 'no input event ever reached the control');
        assert.equal(r.report.summary.manualAction, 1);
        assert.equal(r.report.summary.errors, 0);
        assert.equal(r.report.summary.unverified, 0);
        assert.equal(
            r.report.outcome,
            'ready_for_review',
            JSON.stringify(r.report.reasons),
        );
        assert.ok(
            r.report.warnings.some((w) =>
                /optional field "Current location" needs a person to select a suggestion/.test(
                    w,
                ),
            ),
        );
    });

    it('a REQUIRED typeahead prevents ready_for_review', async () => {
        const path = location(
            '/ta-combobox-required.html',
            `<label>${label('Current location', true)}<div class="application-field"><input type="text" id="locq" name="location" role="combobox" aria-autocomplete="list" required></div></label>`,
        );
        const r = await run(path);
        const f = field(r, 'location');
        assert.equal(f.status, 'manual_action_required');
        assert.equal(f.expected, EXPECTED);
        assert.equal(r.report.outcome, 'input_required');
        assert.match(
            r.report.reasons.join(' '),
            /manual_action_required: Current location/,
        );
    });

    it('other fields are still filled around a skipped typeahead', async () => {
        const path = location(
            '/ta-others.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location" role="combobox" aria-autocomplete="list"></div></label>`,
        );
        const r = await run(path);
        for (const name of ['name', 'email', 'phone', 'org', 'urls[LinkedIn]'])
            assert.equal(field(r, name).status, 'filled_verified', name);
        assert.equal(r.report.resume.verified, true);
    });

    it('the Lever-shaped pairing (visible text + hidden selected-value input + hidden suggestion dropdown), clearing unselected text on blur, is skipped and left empty', async () => {
        server.hits.length = 0;
        const path = location(
            '/ta-lever-shape.html',
            `<label>${label('Current location')}<div class="application-field">
               <input class="location-input" id="locq" type="text" maxlength="100" name="location">
               <input id="selected-location" type="hidden" name="selectedLocation" value="">
               <div class="dropdown-container" style="display:none"><div class="dropdown-results"></div><div class="dropdown-no-results">No location found.</div></div>
             </div></label>`,
            `${PROBE}<script>document.getElementById('locq').addEventListener('blur', (e) => { if (!document.getElementById('selected-location').value) e.target.value = ''; })</script>`,
        );
        const r = await run(path);
        const f = field(r, 'location');
        assert.equal(f.status, 'manual_action_required');
        assert.equal(f.expected, EXPECTED);
        assert.ok(f.control?.signals.includes('selected_value_input'));
        assert.ok(
            f.control?.signals.includes('hidden_input_with_suggestion_list'),
        );
        assert.equal(typed(), false);
        assert.equal(
            r.report.outcome,
            'ready_for_review',
            JSON.stringify(r.report.reasons),
        );
    });

    it('a listbox relationship (aria-controls pointing at a listbox) is recognised', async () => {
        const path = location(
            '/ta-listbox.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location" aria-controls="sugg"><ul id="sugg" role="listbox" hidden></ul></div></label>`,
        );
        const f = field(await run(path), 'location');
        assert.equal(f.status, 'manual_action_required');
        assert.ok(f.control?.signals.includes('listbox_relationship'));
    });

    it('a hidden selected-value input alone is enough (a lookup that stores the chosen id)', async () => {
        const path = location(
            '/ta-selected-only.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" id="locq" name="location"><input type="hidden" name="selectedLocation" value=""></div></label>`,
        );
        assert.equal(
            field(await run(path), 'location').status,
            'manual_action_required',
        );
    });
});

describe('ordinary inputs are never mistaken for typeaheads', () => {
    const ordinary = async (path: string, markup: string) => {
        server.hits.length = 0;
        const p = location(
            path,
            `<label>${label('Current location')}<div class="application-field">${markup}</div></label>`,
        );
        const f = field(await run(p), 'location');
        assert.equal(f.status, 'filled_verified', JSON.stringify(f));
        assert.equal(f.actual, EXPECTED);
        assert.equal(f.control?.composite, false);
        return f;
    };

    it('the base fixture before this change: a plain text input', async () => {
        await ordinary(
            '/ord-plain.html',
            '<input type="text" name="location">',
        );
    });
    it('autocomplete="off" (a browser hint, not a widget)', async () => {
        await ordinary(
            '/ord-ac-off.html',
            '<input type="text" name="location" autocomplete="off">',
        );
    });
    it('autocomplete="address-level2" / "email"-style tokens', async () => {
        await ordinary(
            '/ord-ac-token.html',
            '<input type="text" name="location" autocomplete="address-level2">',
        );
    });
    it('a native datalist (free text works)', async () => {
        await ordinary(
            '/ord-datalist.html',
            '<input type="text" name="location" list="cities"><datalist id="cities"><option value="Springfield"></option></datalist>',
        );
    });
    it('a wrapper whose class merely mentions autocomplete', async () => {
        await ordinary(
            '/ord-wrapper-class.html',
            '<div class="autocomplete-wrapper"><input type="text" name="location"></div>',
        );
    });
    it('aria-expanded alone', async () => {
        await ordinary(
            '/ord-aria-expanded.html',
            '<input type="text" name="location" aria-expanded="false">',
        );
    });

    it('an unrelated hidden input (e.g. a confirmation or token field) does not trigger it', async () => {
        const f = await ordinary(
            '/ord-unrelated-hidden.html',
            '<input type="text" name="location"><input type="hidden" name="csrf_token" value="x">',
        );
        assert.deepEqual(f.control?.signals, []);
    });

    it('a related hidden input with no selected-value naming and no suggestion list is not enough to skip (it is typed, then verified)', async () => {
        const path = location(
            '/ord-related-hidden-only.html',
            `<label>${label('Current location')}<div class="application-field"><input type="text" name="location-search"><input type="hidden" name="location" value=""></div></label>`,
        );
        const f = field(await run(path), 'location-search');
        assert.notEqual(f.status, 'manual_action_required');
        assert.equal(
            f.status,
            'unverified',
            'typed, but the underlying value does not agree',
        );
    });

    it('an email field with a hidden confirmation twin is still filled', async () => {
        const html = base.replace(
            /(<input\s+type="email"[^>]*name="email"[^>]*>|<input[^>]*name="email"[^>]*>)/,
            '$1<input type="hidden" name="email_confirmation" value="">',
        );
        server.add('/ord-email-twin.html', html);
        assert.notEqual(html, base);
        const r = await run('/ord-email-twin.html');
        assert.equal(field(r, 'email').status, 'filled_verified');
    });
});
