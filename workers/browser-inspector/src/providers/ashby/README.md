# Ashby schema adapter (OFFLINE)

Status: **offline milestone only.** Nothing here opens a connection, is called by the worker, or changes the browser guard. It is
pure code plus fixtures and tests (`test/ashby-*.test.js`, enforced by `ashby-offline-safety.test.js`).

## Why it exists
Ashby's job page loads its application form with two GraphQL **POST** queries to `jobs.ashbyhq.com/api/non-user-graphql`. The
inspector's GET/HEAD-only guard blocks them, so no form renders (run 13). The documented public GET job-board API does not
include the form. The `ApiJobPosting` response does: sections, fields, types, required flags and select options.

## Pieces (the transport is deliberately absent)
| file | job |
|---|---|
| `documents.js` | the two approved query documents, pinned by SHA-256 (whitespace-collapsed), with their exact variable shapes. The only file that changes when Ashby changes a query. |
| `target.js` | accepts only `https://jobs.ashbyhq.com/{page}/{uuid}[/application]` and extracts the two variables. |
| `request.js` | `validateRequestBody` / `buildRequest`: single plain object, exact keys, approved operation, document hash equals the pin, exact variables. Refuses batches, mutations, extra operations, aliases, directives, extensions, unexpected or malformed variables. |
| `response.js` | fails closed on structural change, tolerates additions, bounds sizes and counts, drops `fieldValue`. |
| `map.js` | validated schema -> normalized controls with `provider_schema` provenance. |
| `index.js` | `adaptResponses({url, organizationResponse, jobPostingResponse})`. |

A future transport would only call `buildRequest()`, send exactly that body, and pass parsed JSON to `adaptResponses()`.

## What it can prove (from the schema)
Each declared field; its label (title); logical type; required flag; section; options for selects; and whether it belongs to the
application form (22 for the NerdWallet job) or a survey form (7). Required status is per form: 18 required in the application form,
none in the surveys. Sensitive (demographic) questions are flagged by the existing lexicon.

## What it cannot prove
Marked `not_established_from_schema` or listed in `coverage.not_established`: whether a field is visible, collapsed or hidden on
the page; conditional rules (an "If ..." question is only a wording hint); the widget that renders it; page order; submit-time
validation; reCAPTCHA behaviour (only that an action is declared); that the autofill box rendered (only that the org theme offers
it). Yes/No options for Boolean fields are `implied_by_type`. An untitled field stays unlabelled. An unseen field type is kept as
`unknown` and flagged, so a result with unknown types must not be reported as complete.

## Contract mapping (`contract.js`)
`toContractResult()` reduces the controls to the EXISTING worker->Laravel result contract (no new keys). Fields carry
`label_source: provider_schema` (or `unresolved` with no label for an untitled field) and `extraction_source: provider_schema`,
so a provider-reported field is never recorded as DOM-observed. The result is `partial`, with no URLs, and its warnings say
`provider_schema_only` and `visibility_not_established`. Sensitivity, widget, visibility, conditional wording, the application/survey
form and coverage are returned beside the result as `internal`; they are NOT sent or persisted. Laravel validates and persists the
committed snapshot in `tests/Feature/ApplicationInspection/AshbyContractTest.php`.

Limits of the current data model: no column for the application/survey form (only the section title survives, and the untitled
survey section is null), for sensitivity, for widget, or for per-field visibility; `options` stores labels only (no values); `yes_no`
options are implied, not stored.

## Evidence
`test/fixtures/ashby/` holds sanitized, deterministic fixtures from one real capture (`test/support/ashby-sanitize-capture.mjs`),
a golden (`test/support/ashby-refresh-golden.mjs`) and `dom-observation.json`, what a real Chrome showed for the same page.
Tests compare the two: the 15 non-Yes/No application fields match visible DOM controls (14 by label, one by type only), 7 Boolean fields equal
7 Yes/No button pairs by count only, and all survey options match the collapsed groups.

## Unverified
The minimal headers a live call needs; other Ashby organizations and custom job-board domains; terms of service and rate limits;
whether the AI-box container egress allows a non-browser POST; stability of the documents over time.

## Proposed next integration gate (needs separate approval)
1. ~~Provider-schema label source and contract adapter~~ (done, offline).
2. Add a separate transport module behind a new default-off flag: host-pinned, `buildRequest()` only, no cookies or redirects,
   size/time caps, one attempt, and a network-policy review for the AI-box container.
3. Only then one supervised inspection of job 258, compared against the golden.
