// M1.2: the PURE half of generic discovery. No browser: hand-written signals in, logical controls out. These are the rules
// the M0 corpus and golden expectations require, pinned one at a time so a failure names the rule.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { normalize, outcomeFor } from '../src/discovery/normalize.js';
import { toContractFields, toScorable } from '../src/discovery/contract.js';
import { isGenericName, stripRequiredMarker, classifySensitive } from '../src/discovery/lexicon.js';
import { LabelSource, ExtractionSource, InspectionOutcome, StopReason } from '../src/result.js';

let counter = 0;
/** A candidate with sensible defaults; override what the case is about. */
const cand = (over = {}) => {
    counter += 1;

    return {
        n: counter, tag: 'input', type: 'text', role: null, id: `f${counter}`, name: null, testid: null, css: `#f${counter}`,
        userVisible: true, inHiddenSubtree: false, ariaHidden: false, tabindex: null, isNative: true, required: false, ariaRequired: null,
        multiple: false, accept: null, ariaHaspopup: null, ariaControls: null, clipped: false,
        labels: { forLabels: [], wrapping: null, ariaLabel: null, labelledby: [], title: null, placeholder: null },
        group: null, options: null, popup: null, ancestors: [100 + counter, 900], proximity: [], heading: null, section: null, sectionIsGroup: false, labelText: null,
        ...over,
    };
};
const labelled = (text, marker = false) => ({ forLabels: [{ text, marker }], wrapping: null, ariaLabel: null, labelledby: [], title: null, placeholder: null });
const signals = (candidates, extra = {}) => ({ url: 'x', title: 't', candidates, orphanLabels: [], steps: null, counts: { buttons: 0, hiddenInputs: 0 }, ...extra });
const one = (c) => normalize(signals([c])).controls[0];

describe('labels: the accessible name wins, and says where it came from', () => {
    test('label[for], wrapping label, aria-label and aria-labelledby are structural, with the contract source for each', () => {
        const cases = [
            [{ labels: labelled('First Name') }, 'First Name', LabelSource.DomLabelFor],
            [{ labels: { ...labelled('x'), forLabels: [], wrapping: { text: 'Email', marker: false } } }, 'Email', LabelSource.DomWrappingLabel],
            [{ labels: { ...labelled('x'), forLabels: [], ariaLabel: 'Search jobs' } }, 'Search jobs', LabelSource.DomAriaLabel],
            [{ labels: { ...labelled('x'), forLabels: [], labelledby: [{ text: 'City', marker: false, inside: false }] } }, 'City', LabelSource.DomAriaLabelledby],
        ];
        for (const [over, text, source] of cases) {
            const c = one(cand(over));
            assert.equal(c.label.text, text);
            assert.equal(c.label.source, source);
            assert.equal(c.label.confidence, 'structural');
            assert.equal(c.label.basis, 'accessible_name');
        }
    });

    test('the required marker is removed from the label and never becomes required by itself', () => {
        assert.deepEqual(stripRequiredMarker('First Name*'), { text: 'First Name', marked: true });
        assert.deepEqual(stripRequiredMarker('Email *'), { text: 'Email', marked: true });
        assert.deepEqual(stripRequiredMarker('Why us?'), { text: 'Why us?', marked: false });
        const c = one(cand({ labels: labelled('Phone*', true) }));
        assert.equal(c.label.text, 'Phone');
        assert.deepEqual(c.required, { value: null, basis: 'marked_only' });
    });

    test('aria-labelledby ignores a reference to the control\'s own value, so a button dropdown keeps its question', () => {
        const c = one(cand({ tag: 'button', type: null, role: null, isNative: false, ariaHaspopup: 'listbox', labels: { ...labelled('x'), forLabels: [], labelledby: [{ text: 'Primary language', marker: false, inside: false }, { text: 'Select', marker: false, inside: true }] } }));
        assert.equal(c.label.text, 'Primary language');
    });

    test('a generic name ("Attach") is replaced by the heading above it, flagged as inferred', () => {
        const c = one(cand({ type: 'file', clipped: true, labels: labelled('Attach'), heading: 'Resume/CV' }));
        assert.equal(c.label.text, 'Resume/CV');
        assert.equal(c.label.basis, 'heading_context');
        assert.equal(c.label.confidence, 'inferred');
        assert.equal(c.label.generic_name, 'Attach');
        assert.equal(c.label.source, LabelSource.InferredProximity, 'persisted as the closest existing source');
    });

    test('a generic name with no heading stays, honestly marked inferred', () => {
        const c = one(cand({ type: 'file', labels: labelled('Upload') }));
        assert.equal(c.label.text, 'Upload');
        assert.equal(c.label.basis, 'generic_name');
        assert.equal(c.label.confidence, 'inferred');
    });

    test('a REAL label always beats the heading above it', () => {
        const c = one(cand({ labels: labelled('Desired salary (USD)'), heading: 'Compensation' }));
        assert.equal(c.label.text, 'Desired salary (USD)');
        assert.equal(c.label.confidence, 'structural');
    });

    test('a control with no name at all is never given a heading', () => {
        const c = one(cand({ heading: 'Which of these have you used?' }));
        assert.equal(c.label.text, null);
        assert.equal(c.label.source, LabelSource.Unresolved);
    });

    test('placeholder, then preceding text, in that order, both inferred', () => {
        const p = one(cand({ labels: { ...labelled('x'), forLabels: [], placeholder: 'Why do you want to work here?' }, heading: 'Apply' }));
        assert.equal(p.label.text, 'Why do you want to work here?');
        assert.equal(p.label.source, LabelSource.DomPlaceholder);
        assert.equal(p.label.confidence, 'inferred');
        const n = one(cand({ proximity: [{ text: 'Earliest start date', generic: false }] }));
        assert.equal(n.label.text, 'Earliest start date');
        assert.equal(n.label.basis, 'proximity_text');
        assert.equal(n.label.confidence, 'inferred');
    });

    test('a widget\'s own "Select..." prompt ends the search instead of becoming the label', () => {
        const c = one(cand({ proximity: [{ text: 'Select...', generic: true }, { text: 'Some other question', generic: false }] }));
        assert.equal(c.label.source, LabelSource.Unresolved);
        assert.equal(isGenericName('Select...'), true);
    });

    test('generic names are recognised, real ones are not', () => {
        for (const t of ['Attach', 'Upload', 'Choose file', 'Browse', 'Select...', 'Enter manually', 'Search']) assert.equal(isGenericName(t), true, t);
        for (const t of ['Resume/CV', 'Upload your portfolio of work', 'Country', 'Selected country']) assert.equal(isGenericName(t), false, t);
    });
});

describe('logical controls: one row per question, not per input', () => {
    test('radios sharing a name are one radio group, labelled by their legend, with their options', () => {
        const group = { kind: 'fieldset', css: 'fieldset', label: 'Are you authorized to work?', labelSource: 'legend' };
        const r = (id, text) => cand({ type: 'radio', id, name: 'auth', group, labelText: text, labels: labelled(text) });
        const { controls } = normalize(signals([r('a', 'Yes'), r('b', 'No')]));
        assert.equal(controls.length, 1);
        assert.equal(controls[0].kind, 'radio_group');
        assert.equal(controls[0].label.text, 'Are you authorized to work?');
        assert.equal(controls[0].label.source, LabelSource.DomWrappingLabel);
        assert.deepEqual(controls[0].options, { state: 'known_in_dom', count: 2, labels: ['Yes', 'No'] });
        assert.equal(controls[0].id, 'auth');
        assert.equal(controls[0].interaction.mechanism, 'check');
        assert.equal(controls[0].interaction.members.length, 2);
    });

    test('checkboxes sharing a name are a checkbox group (multi); a lone checkbox is a checkbox', () => {
        const group = { kind: 'role_group', css: 'div', label: 'Which have you used?', labelSource: 'labelledby' };
        const k = (id, text) => cand({ type: 'checkbox', id, name: 'skill', group, labelText: text, labels: labelled(text) });
        const grouped = normalize(signals([k('a', 'Kubernetes'), k('b', 'Terraform')])).controls;
        assert.equal(grouped.length, 1);
        assert.equal(grouped[0].kind, 'checkbox_group');
        assert.equal(grouped[0].cardinality, 'multi');
        assert.equal(grouped[0].label.source, LabelSource.DomAriaLabelledby);
        const lone = normalize(signals([cand({ type: 'checkbox', id: 'consent', name: null, labels: labelled('I agree') })])).controls;
        assert.equal(lone[0].kind, 'checkbox');
    });

    test('a combobox absorbs its hidden validation input, its hidden mirror and its popup search box', () => {
        const shared = 555;
        const primary = cand({ id: 'country', role: 'combobox', ariaHaspopup: 'true', labels: labelled('Country*', true), ancestors: [11, shared], ariaRequired: 'true' });
        const validation = cand({ id: null, userVisible: false, ariaHidden: true, tabindex: '-1', css: 'input.v', ancestors: [shared, 1] });
        const mirror = cand({ id: null, type: 'hidden', userVisible: false, css: 'input.m', ancestors: [shared, 1] });
        const search = cand({ id: null, type: 'search', userVisible: false, inHiddenSubtree: true, css: 'input.s', ancestors: [12, shared] });
        const { controls, coverage } = normalize(signals([primary, validation, mirror, search]));
        assert.equal(controls.length, 1, 'ONE logical control');
        assert.deepEqual(controls[0].interaction.auxiliary.map((a) => a.role).sort(), ['mirror_input', 'popup_search', 'validation_input']);
        assert.equal(coverage.auxiliary_controls, 3);
        assert.equal(controls[0].kind, 'select');
        assert.equal(controls[0].widget, 'combobox');
        assert.equal(controls[0].interaction.mechanism, 'open_listbox_and_choose');
    });

    test('an unrelated hidden input far from any widget is not absorbed', () => {
        const primary = cand({ role: 'combobox', labels: labelled('Country'), ancestors: [1, 2, 3, 4, 5, 6] });
        const far = cand({ type: 'hidden', userVisible: false, ancestors: [90, 91, 92, 93, 94, 6] });
        assert.equal(normalize(signals([primary, far])).coverage.auxiliary_controls, 0);
    });

    test('kinds: dropdown widgets are all "select", whatever the markup', () => {
        const native = one(cand({ tag: 'select', type: null, labels: labelled('Mode'), options: [{ text: 'Choose', value: '', disabled: false }, { text: 'Remote', value: 'r', disabled: false }, { text: 'Hybrid', value: 'h', disabled: false }] }));
        assert.equal(native.kind, 'select');
        assert.equal(native.widget, 'native_select');
        assert.deepEqual(native.options, { state: 'known_in_dom', count: 2, labels: ['Remote', 'Hybrid'] }, 'the empty placeholder option is not an option');
        const combo = one(cand({ role: 'combobox', labels: labelled('Time zone') }));
        assert.deepEqual([combo.kind, combo.widget, combo.cardinality], ['select', 'combobox', 'unknown']);
        const button = one(cand({ tag: 'button', type: null, isNative: false, ariaHaspopup: 'listbox', labels: { ...labelled('x'), forLabels: [], ariaLabel: 'Language' } }));
        assert.deepEqual([button.kind, button.widget], ['select', 'listbox_button']);
    });

    test('other kinds: text-like types, tel/email/url, textarea, a file behind a styled button', () => {
        assert.equal(one(cand({ type: 'email', labels: labelled('Email') })).kind, 'email');
        assert.equal(one(cand({ type: 'tel', labels: labelled('Phone') })).kind, 'tel');
        assert.equal(one(cand({ type: null, labels: labelled('Name') })).kind, 'text');
        assert.equal(one(cand({ tag: 'textarea', type: null, labels: labelled('Why') })).kind, 'textarea');
        const file = one(cand({ type: 'file', clipped: true, accept: '.pdf', labels: labelled('Resume') }));
        assert.deepEqual([file.kind, file.widget, file.interaction.mechanism], ['file', 'native_input_hidden_behind_button', 'set_input_files']);
    });

    test('hidden, submit and button inputs are never rows', () => {
        const { controls } = normalize(signals([cand({ type: 'hidden', userVisible: false }), cand({ type: 'submit' }), cand({ type: 'button' })]));
        assert.equal(controls.length, 0);
    });

    test('rows come out in document order and are numbered from zero', () => {
        const { controls } = normalize(signals([cand({ labels: labelled('B') }), cand({ labels: labelled('A') })]));
        assert.deepEqual(controls.map((c) => [c.position, c.label.text]), [[0, 'B'], [1, 'A']]);
    });
});

describe('required: observed, never invented', () => {
    test('aria-required is authoritative, in both directions', () => {
        assert.deepEqual(one(cand({ ariaRequired: 'true' })).required, { value: true, basis: 'aria_required' });
        assert.deepEqual(one(cand({ ariaRequired: 'false', required: true })).required, { value: false, basis: 'aria_required' });
    });
    test('a native control: its required property, or definitively not required', () => {
        assert.deepEqual(one(cand({ required: true })).required, { value: true, basis: 'native_required' });
        assert.deepEqual(one(cand({})).required, { value: false, basis: 'none_declared' });
    });
    test('a custom widget with no signal is unknown, not false', () => {
        assert.deepEqual(one(cand({ isNative: false, role: 'combobox' })).required, { value: null, basis: 'no_signal' });
    });
    test('a marker alone is corroboration only: unknown, not required', () => {
        assert.deepEqual(one(cand({ labels: labelled('Name*', true) })).required, { value: null, basis: 'marked_only' });
    });
    test('a group is required when any member is', () => {
        const g = { kind: 'fieldset', css: 'f', label: 'Q', labelSource: 'legend' };
        const { controls } = normalize(signals([cand({ type: 'radio', name: 'q', group: g, required: true }), cand({ type: 'radio', name: 'q', group: g })]));
        assert.deepEqual(controls[0].required, { value: true, basis: 'native_required' });
    });
});

describe('sensitive: by question and context, never by value', () => {
    const sensitive = (label, section = null, sectionIsGroup = false) => classifySensitive({ label, section, sectionIsGroup }).value;

    test('self-identification questions are sensitive', () => {
        for (const q of ['Gender', 'How would you describe your gender identity? (mark all that apply)', 'Race / ethnicity', 'How would you describe your racial/ethnic background?', 'Are you Hispanic/Latino?', 'Veteran status', 'Are you a veteran or active member of the United States Armed Forces?', 'Disability status', 'Do you have a disability or chronic condition that limits one or more of your major life activities?', 'Do you identify as transgender?', 'How would you describe your sexual orientation?']) {
            assert.equal(sensitive(q), true, q);
        }
    });

    test('look-alikes that only share a word are NOT sensitive', () => {
        for (const q of ['Are you authorized to work in the country where this role is based?', "How many years have you supported veterans' hiring programs?", 'Describe your experience building software for people with disabilities.', 'Desired salary (USD)', 'Describe a race condition you debugged.', 'Gender-neutral language experience (years)']) {
            assert.equal(sensitive(q), false, q);
        }
    });

    test('a self-identification section makes its questions sensitive, structurally when it is a group', () => {
        assert.deepEqual(classifySensitive({ label: 'Pick one', section: 'Voluntary Self-Identification (optional)', sectionIsGroup: true }), { value: true, basis: 'section', confidence: 'structural', matched: 'Voluntary Self-Identification (optional)' });
        assert.equal(classifySensitive({ label: 'Pick one', section: 'U.S. Standard Demographic Questions', sectionIsGroup: false }).confidence, 'inferred');
        assert.equal(sensitive('Years of experience', 'Apply for this job'), false);
    });

    test('the flag is on the control, with its basis, and a question label alone is "inferred"', () => {
        const c = one(cand({ labels: labelled('Veteran Status') }));
        assert.deepEqual([c.sensitive.value, c.sensitive.basis, c.sensitive.confidence], [true, 'label_pattern', 'inferred']);
    });
});

describe('options: what the page holds, and an honest "not in the page"', () => {
    test('a combobox with its listbox in the markup knows its options (hidden or not); one without does not', () => {
        const withList = one(cand({ role: 'combobox', labels: labelled('Country'), popup: { hidden: true, options: ['Canada', 'Germany'] } }));
        assert.deepEqual(withList.options, { state: 'known_hidden_in_dom', count: 2, labels: ['Canada', 'Germany'] });
        const without = one(cand({ role: 'combobox', labels: labelled('Country') }));
        assert.deepEqual(without.options, { state: 'not_in_observed_dom' });
    });
    test('plain inputs have no options', () => assert.equal(one(cand({ labels: labelled('Name') })).options, null));
});

describe('coverage: what was seen but not inspected is never reported as read', () => {
    const hidden = (over) => cand({ userVisible: false, inHiddenSubtree: true, labels: labelled('Visa type'), ...over });

    test('a LABELLED hidden field is a known conditional field and makes the result partial', () => {
        const r = normalize(signals([cand({ labels: labelled('Name') }), hidden({})]));
        assert.deepEqual(r.coverage.known_uninspected, [{ kind: 'hidden_fields_in_markup', count: 1 }]);
        assert.deepEqual(r.warnings, ['conditional_fields_not_inspected']);
        assert.deepEqual(outcomeFor(r), { inspection_outcome: InspectionOutcome.Partial, stop_reason: null });
        assert.equal(r.controls.length, 1, 'and it is not reported as a field');
    });
    test('an UNLABELLED hidden input (a honeypot) is ignored, so ordinary forms are not called partial', () => {
        const r = normalize(signals([cand({ labels: labelled('Name') }), hidden({ labels: labelled('') }), hidden({ labels: labelled('Trap'), ariaHidden: true, tabindex: '-1' })]));
        assert.deepEqual(r.coverage.known_uninspected, []);
        assert.equal(outcomeFor(r).inspection_outcome, InspectionOutcome.Complete);
    });
    test('a label that points at a control that is not in the page is a referenced control we could not see', () => {
        const r = normalize(signals([cand({ id: 'a', labels: labelled('A') })], { orphanLabels: [{ for: 'resume_text', text: 'Enter manually' }, { for: 'a', text: 'A' }] }));
        assert.deepEqual(r.coverage.known_uninspected, [{ kind: 'referenced_controls_absent', count: 1, ids: ['resume_text'] }]);
        assert.deepEqual(r.warnings, ['conditional_fields_not_inspected']);
    });
    test('more steps announced than inspected is partial, with its own warning', () => {
        const r = normalize(signals([cand({ labels: labelled('First name') })], { steps: { current: 1, total: 3, source: 'progress_list' } }));
        assert.deepEqual(r.coverage.known_uninspected, [{ kind: 'more_steps', current: 1, total: 3 }]);
        assert.deepEqual(r.warnings, ['more_steps_not_inspected']);
        assert.equal(outcomeFor(r).inspection_outcome, InspectionOutcome.Partial);
    });
    test('no fields at all keeps the existing partial / no_fields_extracted result', () => {
        assert.deepEqual(outcomeFor(normalize(signals([]))), { inspection_outcome: InspectionOutcome.Partial, stop_reason: StopReason.NoFieldsExtracted });
    });
    test('a plain, fully read form is complete', () => {
        assert.deepEqual(outcomeFor(normalize(signals([cand({ labels: labelled('Name') })]))), { inspection_outcome: InspectionOutcome.Complete, stop_reason: null });
    });
});

describe('the contract mapping: the existing shape, the existing enums, nothing new', () => {
    const controls = normalize(signals([
        cand({ id: 'a', labels: labelled('First Name*', true), ariaRequired: 'true' }),
        cand({ id: 'cv', type: 'file', clipped: true, labels: labelled('Attach'), heading: 'Resume/CV' }),
        cand({ id: 'mode', tag: 'select', type: null, labels: labelled('Mode'), options: [{ text: 'Remote', value: 'r', disabled: false }] }),
        cand({ id: 'x', section: 'S'.repeat(400) }),
    ])).controls;
    const fields = toContractFields(controls, { ariaNames: new Set(['first name']) });

    test('every field has exactly the keys Laravel validates', () => {
        for (const f of fields) assert.deepEqual(Object.keys(f).sort(), ['control_type', 'external_field_id', 'extraction_source', 'label_source', 'options', 'position', 'raw_label', 'required', 'section']);
        assert.deepEqual(fields.map((f) => f.position), [0, 1, 2, 3]);
    });
    test('label and extraction sources are inside the hand-mirrored enums', () => {
        const src = new Set(Object.values(LabelSource));
        for (const f of fields) assert.ok(src.has(f.label_source), f.label_source);
        assert.deepEqual(fields.map((f) => f.extraction_source), [ExtractionSource.Both, ExtractionSource.Dom, ExtractionSource.Dom, ExtractionSource.Dom]);
        // The accessibility tree may still carry the marker: "First Name*" confirms the stripped label too.
        const marked = toContractFields(controls, { ariaNames: new Set(['first name*', 'resume/cv *']) });
        assert.deepEqual(marked.map((f) => f.extraction_source), [ExtractionSource.Both, ExtractionSource.Both, ExtractionSource.Dom, ExtractionSource.Dom]);
        assert.throws(() => toContractFields([{ ...controls[0], label: { ...controls[0].label, source: 'heading_context' } }]), /not in the worker\/Laravel contract/);
    });
    test('the heading-derived label persists as inferred_proximity, as approved', () => {
        assert.deepEqual([fields[1].raw_label, fields[1].label_source], ['Resume/CV', LabelSource.InferredProximity]);
    });
    test('control_type is the normalized kind, required keeps null, options are plain strings or null, section is bounded', () => {
        assert.deepEqual(fields.map((f) => f.control_type), ['text', 'file', 'select', 'text']);
        assert.equal(fields[0].required, true);
        assert.deepEqual(fields[2].options, ['Remote']);
        assert.equal(fields[0].options, null);
        assert.equal(fields[3].section.length, 255);
    });
    test('the scorer sees the richer facts that the contract cannot hold', () => {
        const s = toScorable(controls[1]);
        assert.deepEqual([s.key, s.label, s.kind, s.label_confidence, s.sensitive], ['cv', 'Resume/CV', 'file', 'inferred', false]);
    });
});

describe('the module cannot interact with a page', () => {
    const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src', 'discovery');
    const files = ['lexicon.js', 'normalize.js', 'contract.js'];

    test('the pure modules import nothing from Playwright or the DOM', () => {
        for (const f of files) {
            const src = readFileSync(path.join(dir, f), 'utf8').replace(/\/\/.*$/gm, '');
            assert.ok(!/from 'playwright'|require\('playwright'\)|\bdocument\.|\bwindow\.|\bpage\./.test(src), `${f} touches the browser`);
        }
    });
});
