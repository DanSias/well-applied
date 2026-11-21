// Stage 1 cohort manifests: validation and composition. Pure: it reads a parsed manifest and returns findings and counts.
// A proposed cohort AUTHORISES NOTHING; the validator refuses a manifest that claims otherwise.
export const PROVENANCE = ['production_discovery', 'curated_external', 'stage0_existing'];
export const PLATFORMS = ['greenhouse', 'lever', 'ashby', 'eightfold', 'workday', 'workable', 'smartrecruiters'];
// Only these application-URL statuses may appear in a PROPOSED cohort. `proposed_by_resolution` and `human_verified` exist only
// after Stage 2 has run and a person has reviewed it.
export const PROPOSED_URL_STATUS = ['known_direct', 'unresolved', 'not_applicable'];
const FORBIDDEN_IN_PRODUCTION = ['url', 'title', 'company', 'description', 'location'];
const HAND_REVIEW_RANGE = [8, 12];

export function validateCohort(m) {
    const errors = [];
    const err = (id, msg) => errors.push(`${id}: ${msg}`);
    if (m.schema !== 'eval-cohort/1') errors.push(`unexpected schema ${m.schema}`);
    if (m.status !== 'proposed') errors.push(`status must be "proposed", got ${m.status}`);
    for (const k of ['authorised', 'authorized', 'approved', 'run']) if (k in m) errors.push(`a proposed cohort must not carry "${k}"`);
    const seen = new Set();
    for (const c of m.cases ?? []) {
        if (seen.has(c.id)) err(c.id, 'duplicate id');
        seen.add(c.id);
        if (!PROVENANCE.includes(c.provenance)) err(c.id, `unknown provenance ${c.provenance}`);
        const status = c.application_url?.status;
        if (c.provenance !== 'stage0_existing' && !PROPOSED_URL_STATUS.includes(status)) err(c.id, `application_url.status "${status}" is not allowed in a proposed cohort`);
        if (c.provenance === 'production_discovery') {
            if (!Number.isInteger(c.record_ref?.job_posting_id)) err(c.id, 'a production case needs record_ref.job_posting_id');
            for (const k of FORBIDDEN_IN_PRODUCTION) if (k in c) err(c.id, `a production record's ${k} must not be copied into the manifest`);
            if (c.record_ref && 'url' in c.record_ref) err(c.id, 'a production record\'s URL must be referenced by hash, not copied');
            if (c.listing_url_kind !== 'none' && !/^[0-9a-f]{64}$/.test(c.record_ref?.url_sha256 ?? '')) err(c.id, 'record_ref.url_sha256 is required when the record has a URL');
        }
        if (c.provenance === 'curated_external') {
            if (!/^https:\/\//.test(c.url ?? '')) err(c.id, 'a curated external case needs an https url');
            if ('record_ref' in c) err(c.id, 'a curated external case must not reference a production record');
            if (c.source !== 'curated_external') err(c.id, 'source must be curated_external');
        }
        if (c.listing_url_kind === 'aggregator_listing' && (status !== 'unresolved' || c.application_url.value !== null)) err(c.id, 'an aggregator listing URL is not an employer application URL: it must be unresolved with no value');
        if (c.provenance !== 'stage0_existing' && c.liveness !== 'unverified') err(c.id, 'liveness must be "unverified" until Stage 2 checks it');
        if (!['hand_review', 'provider_schema', 'existing', 'none'].includes(c.ground_truth?.plan)) err(c.id, `unknown ground_truth.plan ${c.ground_truth?.plan}`);
    }
    const hand = (m.cases ?? []).filter((c) => c.ground_truth?.plan === 'hand_review').length;
    if (hand < HAND_REVIEW_RANGE[0] || hand > HAND_REVIEW_RANGE[1]) errors.push(`${hand} cases need hand-reviewed ground truth; expected ${HAND_REVIEW_RANGE.join(' to ')}`);

    return errors;
}

const count = (items, key) => Object.fromEntries(Object.entries(items.reduce((o, i) => ({ ...o, [key(i)]: (o[key(i)] ?? 0) + 1 }), {})).sort(([a], [b]) => a.localeCompare(b)));

export function composition(m) {
    const cases = m.cases;
    const platform = (c) => c.expected_platform?.v ?? 'unknown';
    const byProv = (p) => cases.filter((c) => c.provenance === p);
    const dupGroups = {};
    for (const c of cases) if (c.flags?.duplicate_group) (dupGroups[c.flags.duplicate_group] ??= []).push(c.id);

    return {
        schema: 'eval-cohort-composition/1',
        cohort_version: m.cohort_version,
        total_cases: cases.length,
        new_cases: cases.filter((c) => c.provenance !== 'stage0_existing').length,
        by_provenance: count(cases, (c) => c.provenance),
        by_stratum: count(cases, (c) => c.stratum),
        by_source: count(cases, (c) => c.source ?? 'stage0'),
        by_expected_platform: count(cases, platform),
        platform_by_provenance: Object.fromEntries(PLATFORMS.map((p) => [p, count(cases.filter((c) => platform(c) === p), (c) => c.provenance)])),
        platforms_with_no_case: PLATFORMS.filter((p) => !cases.some((c) => platform(c) === p)),
        platforms_with_only_unverified_or_demo_cases: PLATFORMS.filter((p) => cases.some((c) => platform(c) === p) && cases.filter((c) => platform(c) === p).every((c) => c.flags?.vendor_demo || c.flags?.stale_candidate || c.provenance === 'curated_external')),
        by_listing_url_kind: count(cases.filter((c) => c.listing_url_kind), (c) => c.listing_url_kind),
        by_application_url_status: count(cases.filter((c) => c.application_url), (c) => c.application_url.status),
        by_ground_truth_plan: count(cases, (c) => c.ground_truth.plan),
        hand_review_cases: cases.filter((c) => c.ground_truth.plan === 'hand_review').map((c) => c.id),
        duplicate_pairs: dupGroups,
        stale_candidates: cases.filter((c) => c.flags?.stale_candidate).map((c) => c.id),
        negative_controls: cases.filter((c) => c.flags?.negative_control || c.role === 'stale_or_unreachable_control').map((c) => c.id),
        production_cases: byProv('production_discovery').length,
        curated_external_cases: byProv('curated_external').length,
    };
}

export function renderComposition(c, errors) {
    const t = (title, obj) => [`**${title}:** ${Object.entries(obj).map(([k, v]) => `${k} ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(' · ') || 'none'}`, ''];
    return `${[
        `# Stage 1 proposed cohort: composition (${c.cohort_version})`, '',
        '> PROPOSED. Authorises no discovery, resolution, inspection or application preparation. Every liveness is unverified.', '',
        `Validation: ${errors.length === 0 ? '**passes**' : `**${errors.length} problem(s)**`}`, ...errors.map((e) => `- ${e}`), '',
        `**${c.total_cases} cases** (${c.new_cases} new, ${c.total_cases - c.new_cases} carried from Stage 0): ${c.production_cases} production discovery, ${c.curated_external_cases} curated external.`, '',
        ...t('By provenance', c.by_provenance), ...t('By stratum', c.by_stratum), ...t('By source', c.by_source), ...t('By expected platform', c.by_expected_platform),
        ...t('Platform by provenance', c.platform_by_provenance), `**Platforms with no case:** ${c.platforms_with_no_case.join(', ') || 'none'}`, '',
        `**Platforms resting only on external, demo or stale candidates:** ${c.platforms_with_only_unverified_or_demo_cases.join(', ') || 'none'}`, '',
        ...t('Listing URL kind', c.by_listing_url_kind), ...t('Application URL status', c.by_application_url_status), ...t('Ground-truth plan', c.by_ground_truth_plan),
        `**Hand-reviewed ground truth (${c.hand_review_cases.length}):** ${c.hand_review_cases.join(', ')}`, '',
        `**Duplicate pairs:** ${Object.entries(c.duplicate_pairs).map(([g, ids]) => `${g} (${ids.join(', ')})`).join('; ') || 'none'}`, '',
        `**Stale candidates:** ${c.stale_candidates.join(', ') || 'none'}`, '',
        `**Negative controls:** ${c.negative_controls.join(', ') || 'none'}`,
    ].join('\n')}\n`;
}
