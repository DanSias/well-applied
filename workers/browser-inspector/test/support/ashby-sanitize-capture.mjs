// Offline: turns a locally captured pair of Ashby responses into the committed, minimal fixtures. It makes no request.
//   usage: node test/support/ashby-sanitize-capture.mjs <capture.json> [outDir]
// The capture is an array of {method, url, post, status, res} (what a browser recorded for one public job page). What is
// KEPT is exactly what the adapter reads (plus the form definition, verbatim). What is DROPPED is everything else: the long
// description and compensation text, theme/branding, feature flags, tracking-pixel markup, and Ashby's internal
// serialisation ids. Output is deterministic: same capture in, same bytes out.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const [, , capturePath, outArg] = process.argv;
if (!capturePath) throw new Error('usage: ashby-sanitize-capture.mjs <capture.json> [outDir]');
const out = outArg ?? path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'ashby');
mkdirSync(out, { recursive: true });

const capture = JSON.parse(readFileSync(capturePath, 'utf8'));
const byOp = (op) => capture.find((e) => JSON.parse(e.post).operationName === op);
const org = JSON.parse(byOp('ApiOrganizationFromHostedJobsPageName').res);
const job = JSON.parse(byOp('ApiJobPosting').res);

const dropInternal = (v) => {
    if (Array.isArray(v)) return v.map(dropInternal);
    if (v && typeof v === 'object') {
        return Object.fromEntries(Object.entries(v).filter(([k]) => k !== '__autoSerializationID').map(([k, x]) => [k, dropInternal(x)]));
    }
    return v;
};
const pick = (o, keys) => Object.fromEntries(keys.filter((k) => k in o).map((k) => [k, o[k]]));

const o = org.data.organization;
const orgOut = { data: { organization: { ...pick(o, ['name', 'hostedJobsPageSlug', 'publicWebsite', 'allowJobPostIndexing', 'isDemoOrg', 'timezone', 'recruitingPrivacyPolicyUrl']), theme: pick(o.theme, ['showAutofillApplicationsBox']), __typename: o.__typename } } };

const j = job.data.jobPosting;
const jobOut = {
    data: {
        jobPosting: {
            ...pick(j, ['id', 'title', 'departmentName', 'locationName', 'workplaceType', 'employmentType', 'isListed', 'isConfidential', 'applicationLimitCalloutHtml', 'shouldAskForTextingConsent', 'shouldAskForWhatsAppConsent', 'recaptchaAction']),
            descriptionHtml: '[omitted from the fixture]',
            applicationForm: dropInternal(j.applicationForm),
            surveyForms: dropInternal(j.surveyForms),
            __typename: j.__typename,
        },
    },
};

const bodies = capture.map((e) => JSON.parse(e.post)).sort((a, b) => a.operationName.localeCompare(b.operationName));
const write = (name, v) => writeFileSync(path.join(out, name), JSON.stringify(v, null, 2) + '\n');
write('nerdwallet-org.response.json', orgOut);
write('nerdwallet-job.response.json', jobOut);
write('nerdwallet.requests.json', bodies);
console.log('wrote 3 fixtures to', out);
