// M1.4: generic discovery versus the EXISTING Greenhouse extractor (which stays in production for Greenhouse until a separate
// parity gate passes). Both run over the same pages; every difference must be on a short, named allowlist of intended changes.
// Anything else is a regression in generic discovery, and fails here.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';
import { extract } from '../src/extractors/greenhouse.js';
import { discoverForm } from '../src/discovery/index.js';
import { readOnlyPage } from '../src/discovery/page-adapter.js';

const FIX = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures');
const PAGES = [
    ['the existing Greenhouse fixture', 'greenhouse-form.html'],
    ['the M0 Greenhouse-style replica', 'universal/forms/greenhouse-style-form.html'],
];
const stripMarker = (t) => (t ?? '').replace(/\s*\*+\s*$/, '').trim();

/**
 * The intended differences, each named. A delta is {field, kind}; kind must be one of these. They are exactly the M0 baseline's
 * documented extraction errors plus the radio/checkbox grouping M1 adds.
 */
const ALLOWED = {
    marker_stripped: 'the required marker is no longer part of the label',
    dropdown_typed_select: 'a combobox or listbox is kind "select", not "text"/"combobox"',
    radios_grouped: 'radios sharing a name are one radio_group, labelled by their legend',
    generic_label_from_heading: 'a generic name ("Attach") is replaced by the heading above it, persisted as inferred_proximity',
    widget_duplicate_dropped: 'a widget\'s hidden validation input is no longer a second row',
};

function compare(legacy, generic) {
    const deltas = [];
    const unexplained = [];
    const genericById = new Map(generic.filter((g) => g.external_field_id !== null).map((g) => [g.external_field_id, g]));
    const claimed = new Set();

    // 1. widget duplicates: legacy rows with no id that repeat the label of the row before them (or have none) are dropped
    for (const [i, l] of legacy.entries()) {
        const radioGroup = /^(.+)_[^_]+$/.exec(l.external_field_id ?? '');
        const g = l.external_field_id === null ? null : genericById.get(l.external_field_id);

        if (g) {
            claimed.add(g.external_field_id);
            if (stripMarker(l.raw_label) !== l.raw_label) deltas.push({ field: l.external_field_id, kind: 'marker_stripped' });
            const expectLabel = l.raw_label === null ? null : stripMarker(l.raw_label);
            if (g.raw_label !== expectLabel) {
                if (g.label_source === 'inferred_proximity' && /^(attach|upload|browse|choose file)$/i.test(expectLabel)) deltas.push({ field: l.external_field_id, kind: 'generic_label_from_heading' });
                else unexplained.push(`${l.external_field_id}: label '${l.raw_label}' -> '${g.raw_label}'`);
            }
            if (g.control_type !== l.control_type) {
                if (g.control_type === 'select' && ['text', 'combobox', 'input'].includes(l.control_type)) deltas.push({ field: l.external_field_id, kind: 'dropdown_typed_select' });
                else unexplained.push(`${l.external_field_id}: control_type ${l.control_type} -> ${g.control_type}`);
            }
            for (const k of ['required', 'section']) if (g[k] !== l[k]) unexplained.push(`${l.external_field_id}: ${k} ${l[k]} -> ${g[k]}`);
            if (JSON.stringify(g.options) !== JSON.stringify(l.options)) unexplained.push(`${l.external_field_id}: options ${JSON.stringify(l.options)} -> ${JSON.stringify(g.options)}`);
            if (l.extraction_source === 'both' && g.extraction_source !== 'both') unexplained.push(`${l.external_field_id}: lost the ARIA cross-check`);
            if (g.label_source !== l.label_source && !deltas.some((d) => d.field === l.external_field_id && d.kind === 'generic_label_from_heading')) unexplained.push(`${l.external_field_id}: label_source ${l.label_source} -> ${g.label_source}`);
            if (g.position !== generic.indexOf(g)) unexplained.push(`${l.external_field_id}: position`);
        } else if (l.external_field_id === null && l.control_type === 'input') {
            deltas.push({ field: `row ${i}`, kind: 'widget_duplicate_dropped' });
        } else if (radioGroup && l.control_type === 'radio') {
            const group = generic.find((x) => x.control_type === 'radio_group' && l.external_field_id.startsWith(`${x.external_field_id}_`));
            if (group) {
                if (!claimed.has(`grp:${group.external_field_id}`)) deltas.push({ field: group.external_field_id, kind: 'radios_grouped' });
                claimed.add(`grp:${group.external_field_id}`);
                if (group.section !== l.section) unexplained.push(`${l.external_field_id}: section changed`);
            } else unexplained.push(`${l.external_field_id}: radio lost`);
        } else if (l.external_field_id === null) {
            // A legacy row with no id (a custom widget without one): it must still be there, in the same place, same label.
            const g2 = generic.find((x) => x.external_field_id === null && x.raw_label === stripMarker(l.raw_label));
            if (g2) {
                if (g2.control_type !== l.control_type) deltas.push({ field: l.raw_label, kind: g2.control_type === 'select' ? 'dropdown_typed_select' : 'unexplained' });
                if (g2.required !== l.required) unexplained.push(`${l.raw_label}: required changed`);
            } else unexplained.push(`row ${i} ('${l.raw_label}') has no counterpart`);
        } else unexplained.push(`${l.external_field_id}: no counterpart`);
    }

    // 2. nothing new may appear
    for (const g of generic) {
        const known = (g.external_field_id !== null && claimed.has(g.external_field_id)) || claimed.has(`grp:${g.external_field_id}`) || (g.external_field_id === null && legacy.some((l) => l.external_field_id === null && stripMarker(l.raw_label) === g.raw_label));
        if (!known) unexplained.push(`new row '${g.raw_label}' (${g.external_field_id})`);
    }

    return { deltas, unexplained };
}

describe('generic discovery versus the existing Greenhouse extractor', () => {
    let browser;
    const results = [];

    before(async () => {
        browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
        for (const [name, file] of PAGES) {
            const page = await browser.newPage();
            await page.setContent(readFileSync(path.join(FIX, file), 'utf8'));
            const legacy = (await extract(page)).fields;
            const generic = (await discoverForm(readOnlyPage(page))).fields;
            results.push({ name, legacy, generic, ...compare(legacy, generic) });
            await page.close();
        }
    });
    after(async () => {
        await browser?.close();
    });

    for (const [i, [name]] of PAGES.entries()) {
        test(`${name}: every difference is an intended one`, () => {
            const r = results[i];
            console.log(`\n  ${name}: legacy ${r.legacy.length} rows, generic ${r.generic.length} rows; deltas: ${JSON.stringify(Object.entries(r.deltas.reduce((a, d) => ({ ...a, [d.kind]: (a[d.kind] ?? 0) + 1 }), {})))}`);
            assert.deepEqual(r.unexplained, []);
            for (const d of r.deltas) assert.ok(d.kind in ALLOWED, `${d.field}: ${d.kind} is not an allowed difference`);
        });
    }

    test('what is preserved: order, ids, required flags, options, sections, unresolved rows, and the ARIA cross-check', () => {
        const r = results[0];
        assert.deepEqual(r.legacy.filter((l) => l.control_type !== 'radio').map((l) => l.external_field_id), r.generic.filter((g) => g.control_type !== 'radio_group').map((g) => g.external_field_id));
        assert.equal(r.generic.filter((g) => g.label_source === 'unresolved').length, r.legacy.filter((l) => l.label_source === 'unresolved').length);
        assert.deepEqual(r.generic.find((g) => g.external_field_id === 'country').options, r.legacy.find((l) => l.external_field_id === 'country').options);
        assert.equal(r.generic.find((g) => g.external_field_id === 'first_name').extraction_source, 'both');
    });

    test('the intended improvements are real, on the page the M0 baseline came from', () => {
        const r = results[1];
        assert.equal(r.legacy.length - r.generic.length, 1, 'one duplicate row is gone');
        assert.ok(r.deltas.some((d) => d.kind === 'widget_duplicate_dropped'));
        assert.equal(r.deltas.filter((d) => d.kind === 'dropdown_typed_select').length, 10, 'Country and the nine demographic dropdowns are no longer reported as text');
        assert.equal(r.deltas.filter((d) => d.kind === 'generic_label_from_heading').length, 2);
    });
});
