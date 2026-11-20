// From facts about controls to logical controls. PURE: no DOM, no browser, no clock. Input is the Signals shape documented in
// signals.js; output is one entry per LOGICAL control (a person's idea of "a question"), not per input element.
//
// What it decides, and how it says so:
//   - which elements are one control (a radio group; a combobox with its hidden validation, mirror and popup-search inputs),
//   - a label, with where it came from (source), what kind of evidence that is (basis) and how sure we are (confidence),
//   - whether it is required, and on what basis, and null when nothing says (never a guess),
//   - whether it is a sensitive demographic question, and why,
//   - what the options are, or honestly "not in the page",
//   - how a future preparer would interact with it (a description only: nothing here ever interacts).
// And what it saw but did NOT inspect (hidden fields, further steps) so a caller never claims full coverage it does not have.
import { isGenericName, stripRequiredMarker, classifySensitive } from './lexicon.js';

const NON_FIELD_TYPES = new Set(['hidden', 'submit', 'button', 'image', 'reset']);
const POPUP_VALUES = new Set(['listbox', 'true']);

const hasText = (x) => typeof x === 'string' && x.trim() !== '';
const clean = (x) => (x ?? '').replace(/\s+/g, ' ').trim();

const isComboInput = (c) => c.role === 'combobox';
const isListboxButton = (c) => (c.tag === 'button' || c.role === 'button') && POPUP_VALUES.has(String(c.ariaHaspopup));
const isWidgetPrimary = (c) => isComboInput(c) || isListboxButton(c) || c.role === 'listbox';
const isReportable = (c) => c.userVisible && !NON_FIELD_TYPES.has(c.type);

const textOf = (list) => list.map((l) => clean(l.text)).find(Boolean) ?? null;

/** Did anything on this candidate name it? (Used to tell a labelled hidden field from an unlabelled honeypot.) */
function hasAnyName(c) {
    const l = c.labels;

    return l.forLabels.some((x) => hasText(x.text)) || (l.wrapping && hasText(l.wrapping.text)) || hasText(l.ariaLabel) || l.labelledby.some((x) => !x.inside && hasText(x.text));
}

/** Which marker did any label of this control show? */
function showsMarker(c, groupMarker) {
    const l = c.labels;

    return Boolean(groupMarker) || l.forLabels.some((x) => x.marker) || Boolean(l.wrapping?.marker) || l.labelledby.some((x) => !x.inside && x.marker);
}

// ---- 1. which elements belong to which widget -------------------------------------------------------------------------

/**
 * A non-user-visible input (a hidden validation input, a hidden mirror, a search box inside a popup) belongs to the widget
 * whose container it shares, when that container is close (a few levels). Returns a Map from primary candidate to its auxiliaries.
 */
function attachAuxiliaries(cands) {
    const primaries = cands.filter((c) => isReportable(c) && isWidgetPrimary(c));
    const byPrimary = new Map(primaries.map((p) => [p, []]));
    const consumed = new Set();

    for (const u of cands) {
        if (isReportable(u) && u.userVisible) continue; // a visible control is its own control
        if (primaries.length === 0) continue;

        let best = null;
        for (const p of primaries) {
            const shared = u.ancestors.findIndex((a) => p.ancestors.includes(a));
            if (shared === -1 || shared > 3) continue;
            const distance = Math.abs(u.n - p.n);
            if (best === null || distance < best.distance) best = { p, distance };
        }
        if (best === null) continue;

        const role = u.type === 'hidden' ? 'mirror_input' : u.type === 'search' || u.inHiddenSubtree ? 'popup_search' : 'validation_input';
        byPrimary.get(best.p).push({ role, css: u.css });
        consumed.add(u);
    }

    return { byPrimary, consumed };
}

// ---- 2. one row per logical control ------------------------------------------------------------------------------------

/** @returns {Array<{kind: string, anchor: object, members: object[]}>} in document order */
function groupRows(reportable) {
    const rows = [];
    const radios = new Map();
    const checks = new Map();

    for (const c of reportable) {
        if (c.type === 'radio' || c.role === 'radio') {
            const key = c.name ?? c.group?.css ?? `radio:${c.n}`;
            if (!radios.has(key)) radios.set(key, { kind: 'radio_group', anchor: c, members: [] });
            radios.get(key).members.push(c);
        } else if (c.type === 'checkbox') {
            const key = c.name ?? c.group?.css ?? null;
            if (key === null) rows.push({ kind: 'checkbox', anchor: c, members: [c] });
            else {
                if (!checks.has(key)) checks.set(key, { kind: 'checkbox_group', anchor: c, members: [] });
                checks.get(key).members.push(c);
            }
        } else {
            rows.push({ kind: 'single', anchor: c, members: [c] });
        }
    }

    // A "group" of one checkbox is just a checkbox.
    for (const g of checks.values()) rows.push(g.members.length === 1 ? { kind: 'checkbox', anchor: g.anchor, members: g.members } : g);
    rows.push(...radios.values());

    return rows.sort((a, b) => a.anchor.n - b.anchor.n);
}

// ---- 3. labels ----------------------------------------------------------------------------------------------------------

/**
 * Accessible name first (it always wins over any heading), then weaker evidence, each tagged. A name that is merely generic
 * ("Attach", "Upload") is replaced by the heading above it, but only then: a control with no name at all is never given a
 * heading, because the nearest heading is as likely to belong to the question before it.
 */
function resolveLabel(c, { group, isGroupRow }) {
    const l = c.labels;
    const record = (text, source, basis, confidence, marker, generic = null) => {
        const s = stripRequiredMarker(text);

        return { text: s.text, source, basis, confidence, marker: marker || s.marked, generic_name: generic };
    };

    let structural = null;

    if (isGroupRow && group && hasText(group.label)) {
        const source = { legend: 'dom_wrapping_label', labelledby: 'dom_aria_labelledby', aria_label: 'dom_aria_label' }[group.labelSource] ?? 'dom_aria_labelledby';
        structural = record(group.label, source, 'accessible_name', 'structural', false);
    } else if (!isGroupRow) {
        const named = l.labelledby.filter((x) => !x.inside && hasText(x.text));
        if (named.length > 0) structural = record(named.map((x) => clean(x.text)).join(' '), 'dom_aria_labelledby', 'accessible_name', 'structural', named.some((x) => x.marker));
        else if (hasText(l.ariaLabel)) structural = record(l.ariaLabel, 'dom_aria_label', 'accessible_name', 'structural', false);
        else if (textOf(l.forLabels)) structural = record(textOf(l.forLabels), 'dom_label_for', 'accessible_name', 'structural', l.forLabels.some((x) => x.marker));
        else if (l.wrapping && hasText(l.wrapping.text)) structural = record(l.wrapping.text, 'dom_wrapping_label', 'accessible_name', 'structural', l.wrapping.marker);
        // A button dropdown inside a labelled group takes the group's name (its own name is just its current value).
        else if (isListboxButton(c) && group && hasText(group.label)) structural = record(group.label, 'dom_aria_labelledby', 'accessible_name', 'structural', false);
    }

    if (structural !== null && !isGenericName(structural.text)) return structural;

    const generic = structural?.text ?? null;

    if (generic !== null) {
        // The control has a name, but it only says what the control is. The heading says what it is for.
        if (hasText(c.heading)) return record(c.heading, 'inferred_proximity', 'heading_context', 'inferred', structural.marker, generic);

        return { ...structural, confidence: 'inferred', basis: 'generic_name', generic_name: generic };
    }

    if (!isGroupRow && hasText(l.placeholder)) return record(l.placeholder, 'dom_placeholder', 'placeholder', 'inferred', false);

    // No name at all: the text just before it, never a heading. A widget's own prompt ("Select...") ends the search.
    for (const near of c.proximity ?? []) {
        if (near.generic) break;
        if (hasText(near.text)) return record(near.text, 'inferred_proximity', 'proximity_text', 'inferred', false);
    }

    return { text: null, source: 'unresolved', basis: 'unresolved', confidence: 'unresolved', marker: false, generic_name: null };
}

// ---- 4. required ---------------------------------------------------------------------------------------------------------

/**
 * aria-required, when present, is authoritative. A native control's own `required` is definitive either way. A required
 * MARKER alone (an asterisk) is corroboration, not proof, so it yields null ("unknown"), never true. A custom widget with no
 * signal at all is null. Nothing here invents a value.
 */
function resolveRequired(row, marker) {
    const members = row.members;
    const a = row.anchor;

    if (row.kind === 'radio_group' || row.kind === 'checkbox_group') {
        if (members.some((m) => m.ariaRequired === 'true')) return { value: true, basis: 'aria_required' };
        if (members.some((m) => m.required)) return { value: true, basis: 'native_required' };

        return { value: false, basis: 'none_declared' };
    }
    if (a.ariaRequired !== null) return { value: a.ariaRequired === 'true', basis: 'aria_required' };
    if (a.isNative) {
        if (a.required) return { value: true, basis: 'native_required' };

        return marker ? { value: null, basis: 'marked_only' } : { value: false, basis: 'none_declared' };
    }

    return { value: null, basis: marker ? 'marked_only' : 'no_signal' };
}

// ---- 5. kind, widget, options ---------------------------------------------------------------------------------------------

const TEXTLIKE = new Set(['text', 'search', '', null, undefined]);

function describeControl(row) {
    const a = row.anchor;

    if (row.kind === 'radio_group') return { kind: 'radio_group', cardinality: 'single', widget: 'native_input', mechanism: 'check' };
    if (row.kind === 'checkbox_group') return { kind: 'checkbox_group', cardinality: 'multi', widget: 'native_input', mechanism: 'check' };
    if (row.kind === 'checkbox') return { kind: 'checkbox', cardinality: null, widget: 'native_input', mechanism: 'check' };
    if (a.tag === 'select') return { kind: 'select', cardinality: a.multiple ? 'multi' : 'single', widget: 'native_select', mechanism: 'select_option' };
    if (isComboInput(a)) return { kind: 'select', cardinality: 'unknown', widget: 'combobox', mechanism: 'open_listbox_and_choose' };
    if (isListboxButton(a)) return { kind: 'select', cardinality: 'unknown', widget: 'listbox_button', mechanism: 'open_listbox_and_choose' };
    if (a.role === 'listbox') return { kind: 'select', cardinality: 'unknown', widget: 'listbox', mechanism: 'open_listbox_and_choose' };
    if (a.tag === 'textarea') return { kind: 'textarea', cardinality: null, widget: 'native_input', mechanism: 'fill' };
    if (a.type === 'file') return { kind: 'file', cardinality: a.multiple ? 'multi' : 'single', widget: a.clipped ? 'native_input_hidden_behind_button' : 'native_input', mechanism: 'set_input_files' };

    const type = TEXTLIKE.has(a.type) ? 'text' : a.type;

    return { kind: type, cardinality: null, widget: 'native_input', mechanism: 'fill' };
}

function describeOptions(row, described) {
    const a = row.anchor;

    if (row.kind === 'radio_group' || row.kind === 'checkbox_group') {
        const labels = row.members.map((m) => clean(m.labelText)).filter(Boolean);

        return { state: 'known_in_dom', count: row.members.length, labels };
    }
    if (a.tag === 'select') {
        const labels = (a.options ?? []).filter((o) => !o.disabled && o.value !== '').map((o) => clean(o.text));

        return labels.length > 0 ? { state: 'known_in_dom', count: labels.length, labels } : { state: 'unknown' };
    }
    if (described.kind === 'select') {
        return a.popup ? { state: a.popup.hidden ? 'known_hidden_in_dom' : 'known_in_dom', count: a.popup.options.length, labels: a.popup.options.map(clean) } : { state: 'not_in_observed_dom' };
    }

    return null;
}

// ---- 6. the whole page ----------------------------------------------------------------------------------------------------

/**
 * @param {object} signals see signals.js
 * @returns {{controls: object[], coverage: object, warnings: string[]}}
 */
export function normalize(signals) {
    const cands = signals.candidates;
    const { byPrimary, consumed } = attachAuxiliaries(cands);
    const reportable = cands.filter((c) => isReportable(c) && !consumed.has(c));
    const rows = groupRows(reportable);
    let genericReplaced = 0;
    let unresolved = 0;

    const controls = rows.map((row, position) => {
        const a = row.anchor;
        const isGroupRow = row.kind === 'radio_group' || row.kind === 'checkbox_group';
        const label = resolveLabel(a, { group: a.group, isGroupRow });
        if (label.basis === 'heading_context') genericReplaced += 1;
        if (label.source === 'unresolved') unresolved += 1;

        const described = describeControl(row);
        const required = resolveRequired(row, showsMarker(a, label.marker));
        const sensitive = classifySensitive({ label: label.text, section: a.section, sectionIsGroup: a.sectionIsGroup });
        const options = describeOptions(row, described);

        return {
            position,
            id: (isGroupRow ? a.name : null) ?? a.id ?? a.name ?? a.testid ?? null,
            kind: described.kind,
            cardinality: described.cardinality,
            widget: described.widget,
            label: { text: label.text, source: label.source, basis: label.basis, confidence: label.confidence, generic_name: label.generic_name },
            required,
            sensitive,
            section: hasText(a.section) ? clean(a.section) : null,
            options,
            interaction: {
                mechanism: described.mechanism,
                primary: { css: a.css, id: a.id, name: a.name, tag: a.tag, type: a.type, role: a.role },
                members: isGroupRow ? row.members.map((m) => ({ css: m.css, label: clean(m.labelText) || null })) : [],
                auxiliary: byPrimary.get(a) ?? [],
            },
        };
    });

    // What the page holds that this pass did not inspect.
    const present = new Set(cands.map((c) => c.id).filter(Boolean));
    const hiddenLabelled = cands.filter((c) => !consumed.has(c) && !c.userVisible && c.inHiddenSubtree && !NON_FIELD_TYPES.has(c.type) && hasAnyName(c) && !(c.ariaHidden && c.tabindex === '-1'));
    const orphan = (signals.orphanLabels ?? []).filter((o) => !present.has(o.for));
    const knownUninspected = [];
    if (hiddenLabelled.length > 0) knownUninspected.push({ kind: 'hidden_fields_in_markup', count: hiddenLabelled.length });
    if (orphan.length > 0) knownUninspected.push({ kind: 'referenced_controls_absent', count: orphan.length, ids: orphan.map((o) => o.for) });
    if ((signals.steps?.total ?? 0) > 1) knownUninspected.push({ kind: 'more_steps', current: signals.steps.current ?? null, total: signals.steps.total });

    const warnings = [];
    if (hiddenLabelled.length > 0 || orphan.length > 0) warnings.push('conditional_fields_not_inspected');
    if ((signals.steps?.total ?? 0) > 1) warnings.push('more_steps_not_inspected');

    return {
        controls,
        coverage: {
            fields_reported: controls.length,
            auxiliary_controls: consumed.size,
            hidden_candidates_not_reported: cands.filter((c) => !c.userVisible && !consumed.has(c) && !NON_FIELD_TYPES.has(c.type)).length,
            generic_labels_replaced: genericReplaced,
            unresolved_labels: unresolved,
            known_uninspected: knownUninspected,
        },
        warnings,
    };
}

/**
 * The outcome a result deserves: complete only when something was read AND nothing known was left unread. Reuses the existing
 * outcome and stop-reason vocabulary (no new values: the worker and Laravel stay in step).
 */
export function outcomeFor({ controls, coverage }) {
    if (controls.length === 0) return { inspection_outcome: 'partial', stop_reason: 'no_fields_extracted' };
    if (coverage.known_uninspected.length > 0) return { inspection_outcome: 'partial', stop_reason: null };

    return { inspection_outcome: 'complete', stop_reason: null };
}
