// Validates parsed GraphQL responses from the two approved queries and reduces each to the few facts the mapper reads.
//
// Fails CLOSED on structure: if the shape the mapper depends on moves (a missing form, sections that are not a list, a field with
// no path or type), the whole response is refused with a reason naming the path, rather than guessed at. It is TOLERANT of
// additions: unknown extra keys are ignored, and a field TYPE this adapter has not seen is kept and flagged for the mapper,
// not refused. Nothing a candidate could have entered is carried forward (fieldValue is dropped by construction), and no
// markup is carried except to note that a notice exists.

export const MAX_RESPONSE_CHARS = 1_000_000;
export const LIMITS = Object.freeze({ forms: 12, sections: 40, fields: 300, options: 500, text: 2000 });

const refuse = (reason, detail = null) => ({ ok: false, reason, detail });
const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const text = (v) => (typeof v === 'string' ? v : null);

function envelope(json) {
    if (!isObject(json)) return refuse('not_an_object');
    if (Array.isArray(json.errors) ? json.errors.length > 0 : json.errors != null) return refuse('graphql_errors', String(json.errors?.[0]?.message ?? '').slice(0, 120) || null);
    if (!isObject(json.data)) return refuse('missing_data');

    return null;
}

/** @returns {{ok: true, organization: object} | {ok: false, reason: string, detail: string|null}} */
export function validateOrganizationResponse(json, { organizationHostedJobsPageName } = {}) {
    const bad = envelope(json);
    if (bad) return bad;
    const o = json.data.organization;
    if (o === null) return refuse('organization_not_found');
    if (!isObject(o)) return refuse('schema_changed', 'data.organization');
    if (typeof o.name !== 'string' || o.name === '') return refuse('schema_changed', 'data.organization.name');
    if (typeof o.hostedJobsPageSlug !== 'string' || o.hostedJobsPageSlug === '') return refuse('schema_changed', 'data.organization.hostedJobsPageSlug');
    if (organizationHostedJobsPageName !== undefined && o.hostedJobsPageSlug.toLowerCase() !== organizationHostedJobsPageName.toLowerCase()) return refuse('organization_mismatch');

    return {
        ok: true,
        organization: {
            name: o.name.slice(0, LIMITS.text),
            hosted_jobs_page_slug: o.hostedJobsPageSlug,
            public_website: text(o.publicWebsite),
            timezone: text(o.timezone),
            recruiting_privacy_policy_url: text(o.recruitingPrivacyPolicyUrl),
            // A flag the organisation's page theme carries; it says an autofill-from-resume box is OFFERED, not that it rendered.
            autofill_from_resume_offered: typeof o.theme?.showAutofillApplicationsBox === 'boolean' ? o.theme.showAutofillApplicationsBox : null,
        },
    };
}

function reduceForm(form, at, tally) {
    if (!isObject(form)) return refuse('schema_changed', at);
    if (!Array.isArray(form.sections)) return refuse('schema_changed', `${at}.sections`);
    if (form.sections.length > LIMITS.sections) return refuse('too_many_sections', at);

    const sections = [];
    for (const [si, s] of form.sections.entries()) {
        const sat = `${at}.sections[${si}]`;
        if (!isObject(s)) return refuse('schema_changed', sat);
        if (s.title !== null && s.title !== undefined && typeof s.title !== 'string') return refuse('schema_changed', `${sat}.title`);
        if (s.isHidden !== null && s.isHidden !== undefined && typeof s.isHidden !== 'boolean') return refuse('schema_changed', `${sat}.isHidden`);
        if (!Array.isArray(s.fieldEntries)) return refuse('schema_changed', `${sat}.fieldEntries`);

        const entries = [];
        for (const [ei, e] of s.fieldEntries.entries()) {
            const eat = `${sat}.fieldEntries[${ei}]`;
            if (!isObject(e) || !isObject(e.field)) return refuse('schema_changed', `${eat}.field`);
            const f = e.field;
            if (typeof f.path !== 'string' || f.path === '') return refuse('schema_changed', `${eat}.field.path`);
            if (typeof f.type !== 'string' || f.type === '') return refuse('schema_changed', `${eat}.field.type`);
            if (f.title !== null && f.title !== undefined && typeof f.title !== 'string') return refuse('schema_changed', `${eat}.field.title`);
            if (e.isRequired !== null && e.isRequired !== undefined && typeof e.isRequired !== 'boolean') return refuse('schema_changed', `${eat}.isRequired`);
            if (e.isHidden !== null && e.isHidden !== undefined && typeof e.isHidden !== 'boolean') return refuse('schema_changed', `${eat}.isHidden`);
            if (f.isMany !== null && f.isMany !== undefined && typeof f.isMany !== 'boolean') return refuse('schema_changed', `${eat}.field.isMany`);
            if ((f.title?.length ?? 0) > LIMITS.text || f.path.length > 255) return refuse('string_too_long', eat);

            let options = null;
            if (f.selectableValues !== null && f.selectableValues !== undefined) {
                if (!Array.isArray(f.selectableValues)) return refuse('schema_changed', `${eat}.field.selectableValues`);
                if (f.selectableValues.length > LIMITS.options) return refuse('too_many_options', eat);
                options = [];
                for (const [oi, o] of f.selectableValues.entries()) {
                    if (!isObject(o) || typeof o.label !== 'string' || typeof o.value !== 'string') return refuse('schema_changed', `${eat}.field.selectableValues[${oi}]`);
                    if (o.label.length > LIMITS.text || o.value.length > LIMITS.text) return refuse('string_too_long', `${eat}.field.selectableValues[${oi}]`);
                    options.push({ label: o.label, value: o.value });
                }
            }

            tally.fields += 1;
            if (tally.fields > LIMITS.fields) return refuse('too_many_fields');
            if (tally.paths.has(f.path)) return refuse('duplicate_field_path', f.path);
            tally.paths.add(f.path);

            entries.push({
                at: eat,
                path: f.path,
                schema_id: typeof f.id === 'string' ? f.id : null,
                type: f.type,
                title: f.title ?? null,
                is_many: f.isMany === true,
                options,
                required: typeof e.isRequired === 'boolean' ? e.isRequired : null,
                hidden: e.isHidden === true,
            });
        }
        sections.push({ at: sat, title: s.title ?? null, hidden: s.isHidden === true, entries });
    }

    return { ok: true, sections };
}

/** @returns {{ok: true, jobPosting: object} | {ok: false, reason: string, detail: string|null}} */
export function validateJobPostingResponse(json, { jobPostingId } = {}) {
    if (isObject(json) && JSON.stringify(json).length > MAX_RESPONSE_CHARS) return refuse('response_too_large');
    const bad = envelope(json);
    if (bad) return bad;
    const j = json.data.jobPosting;
    if (j === null) return refuse('job_not_found');
    if (!isObject(j)) return refuse('schema_changed', 'data.jobPosting');
    if (typeof j.id !== 'string') return refuse('schema_changed', 'data.jobPosting.id');
    if (jobPostingId !== undefined && j.id.toLowerCase() !== jobPostingId.toLowerCase()) return refuse('job_id_mismatch');
    if (j.applicationForm === null || j.applicationForm === undefined) return refuse('missing_application_form');
    if (!Array.isArray(j.surveyForms ?? [])) return refuse('schema_changed', 'data.jobPosting.surveyForms');
    if ((j.surveyForms ?? []).length > LIMITS.forms) return refuse('too_many_forms');

    const tally = { fields: 0, paths: new Set() };
    const application = reduceForm(j.applicationForm, 'applicationForm', tally);
    if (!application.ok) return application;
    const surveys = [];
    for (const [i, form] of (j.surveyForms ?? []).entries()) {
        const s = reduceForm(form, `surveyForms[${i}]`, tally);
        if (!s.ok) return s;
        surveys.push(s.sections);
    }

    return {
        ok: true,
        jobPosting: {
            id: j.id,
            title: text(j.title),
            is_listed: typeof j.isListed === 'boolean' ? j.isListed : null,
            recaptcha_action: text(j.recaptchaAction),
            // Only THAT a notice exists (applications are limited for this role); its text is not carried.
            application_limit_notice: typeof j.applicationLimitCalloutHtml === 'string' && j.applicationLimitCalloutHtml.trim() !== '',
            application_form: application.sections,
            survey_forms: surveys,
        },
    };
}
