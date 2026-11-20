// Ashby form schema -> normalized controls. Pure: reads only what response.js validated, never the query documents, never a page.
//
// A control here has the same shape as one from the DOM normalizer (src/discovery/normalize.js) so the later stages can treat them
// alike, with provider-schema provenance in place of DOM evidence. It does NOT map to the worker->Laravel contract: that
// contract's label_source enum has no provider-schema value, and adding one (Laravel enum + WorkerContractSyncTest) is the
// integration gate's decision, not this milestone's.
//
// What the schema ESTABLISHES: each declared field, its label (title), logical type, required flag, section, options for
// selects, and which form (application or survey) it belongs to. What it does NOT: whether a field is visible, collapsed or
// conditional on a page, the widget that renders it, or the order a page shows them in. Those are marked, never assumed.
import { classifySensitive } from '../../discovery/lexicon.js';

const NOT_ESTABLISHED = 'not_established_from_schema';
const clean = (s) => (typeof s === 'string' ? s.replace(/\s+/g, ' ').trim() : '');

/** Ashby field type -> logical kind. A type not listed here is KEPT as kind 'unknown' and flagged, never guessed. */
const TYPES = Object.freeze({
    String: { kind: 'text', cardinality: null, mechanism: 'fill' },
    Email: { kind: 'email', cardinality: null, mechanism: 'fill' },
    Phone: { kind: 'tel', cardinality: null, mechanism: 'fill' },
    LongText: { kind: 'textarea', cardinality: null, mechanism: 'fill' },
    Boolean: { kind: 'yes_no', cardinality: 'single', mechanism: 'choose' },
    ValueSelect: { kind: 'select', cardinality: 'single', mechanism: 'choose' },
    MultiValueSelect: { kind: 'select', cardinality: 'multi', mechanism: 'choose' },
    File: { kind: 'file', cardinality: 'single', mechanism: 'set_input_files' },
});

const describeOptions = (entry, type) => {
    if (type === TYPES.Boolean) return { state: 'implied_by_type', count: 2, labels: ['Yes', 'No'], values: null };
    if (type?.kind !== 'select') return null;
    if (entry.options === null || entry.options.length === 0) return { state: 'unknown', count: null, labels: null, values: null };

    return { state: 'known_in_schema', count: entry.options.length, labels: entry.options.map((o) => clean(o.label)), values: entry.options.map((o) => o.value) };
};

function mapEntry(entry, { form, formIndex, section, operation, position }) {
    const known = TYPES[entry.type] ?? null;
    const label = clean(entry.title);
    const hidden = entry.hidden || section.hidden;
    const sensitive = classifySensitive({ label, section: section.title, sectionIsGroup: true });

    return {
        position,
        id: entry.path,
        schema_id: entry.schema_id,
        kind: known?.kind ?? 'unknown',
        cardinality: known ? (known.kind === 'file' && entry.is_many ? 'multi' : known.cardinality) : null,
        widget: NOT_ESTABLISHED,
        label: label === '' ? { text: null, source: 'provider_schema', basis: 'title_empty', confidence: 'none', generic_name: false } : { text: label, source: 'provider_schema', basis: 'field_title', confidence: 'declared', generic_name: false },
        required: entry.required === null ? { value: null, basis: 'no_signal' } : { value: entry.required, basis: 'provider_schema' },
        sensitive,
        section: clean(section.title) || null,
        form,
        form_index: formIndex,
        options: describeOptions(entry, known),
        visibility: hidden ? { state: 'hidden_by_schema', basis: 'isHidden' } : { state: NOT_ESTABLISHED, basis: 'schema_has_no_render_state' },
        // Wording only: the schema carries no conditional rule, so "If ..." is a hint a person should confirm, not a fact.
        conditional_hint: /^if\b/i.test(label) ? { state: 'label_wording_only', matched: 'if' } : null,
        interaction: { mechanism: known?.mechanism ?? null, primary: null, members: [], auxiliary: [] },
        provenance: { source: 'provider_schema', provider: 'ashby', operation, path: entry.at, schema_type: entry.type },
    };
}

/**
 * @param {{jobPosting: object, organization?: object|null}} validated outputs of validateJobPostingResponse / validateOrganizationResponse
 */
export function mapAshbyForm({ jobPosting, organization = null }) {
    const operation = 'ApiJobPosting';
    const controls = [];
    const sections = [];
    const add = (form, formIndex, secs) => {
        for (const section of secs) {
            sections.push({ form, form_index: formIndex, title: clean(section.title) || null, fields: section.entries.length, hidden: section.hidden });
            for (const entry of section.entries) controls.push(mapEntry(entry, { form, formIndex, section, operation, position: controls.length }));
        }
    };
    add('application_form', 0, jobPosting.application_form);
    jobPosting.survey_forms.forEach((secs, i) => add('survey_form', i, secs));

    const count = (f) => controls.filter(f).length;
    const byType = {};
    for (const c of controls) byType[c.provenance.schema_type] = (byType[c.provenance.schema_type] ?? 0) + 1;

    const warnings = ['visibility_not_established'];
    if (count((c) => c.conditional_hint !== null) > 0) warnings.push('conditional_logic_not_in_schema');
    if (count((c) => c.kind === 'unknown') > 0) warnings.push('unknown_field_type');
    if (count((c) => c.label.text === null) > 0) warnings.push('unlabelled_control');
    if (count((c) => c.visibility.state === 'hidden_by_schema') > 0) warnings.push('hidden_by_schema');
    if (jobPosting.application_limit_notice) warnings.push('application_limit_notice');
    if (jobPosting.is_listed === false) warnings.push('job_not_listed');

    return {
        provider: 'ashby',
        operation,
        job_posting_id: jobPosting.id,
        controls,
        coverage: {
            application_form_fields: count((c) => c.form === 'application_form'),
            survey_fields: count((c) => c.form === 'survey_form'),
            total: controls.length,
            required_declared: count((c) => c.required.value === true),
            required_application_form: count((c) => c.form === 'application_form' && c.required.value === true),
            required_survey_form: count((c) => c.form === 'survey_form' && c.required.value === true),
            sensitive_flagged: count((c) => c.sensitive.value),
            hidden_by_schema: count((c) => c.visibility.state === 'hidden_by_schema'),
            unknown_types: count((c) => c.kind === 'unknown'),
            unlabelled: count((c) => c.label.text === null),
            by_type: Object.fromEntries(Object.entries(byType).sort(([a], [b]) => a.localeCompare(b))),
            sections,
            // Everything the schema cannot tell a reader, said once and explicitly.
            not_established: ['visibility_on_page', 'collapsed_or_expanded', 'conditional_rules', 'widget_used', 'render_order_on_page', 'submit_time_validation'],
        },
        page_features: {
            // Declared by the schema or the organisation's theme: that they are OFFERED, not that a page rendered them.
            recaptcha_declared: jobPosting.recaptcha_action === null ? null : { action: jobPosting.recaptcha_action },
            autofill_from_resume_offered: organization?.autofill_from_resume_offered ?? null,
            application_limit_notice: jobPosting.application_limit_notice,
        },
        organization: organization === null ? null : { name: organization.name, hosted_jobs_page_slug: organization.hosted_jobs_page_slug },
        warnings,
    };
}
