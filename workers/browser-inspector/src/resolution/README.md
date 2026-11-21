# Destination Resolution

`resolveDestination(sourceUrl, options)` turns a discovered job's source URL into a **proposed** employer application destination, or a precise reason it could not.

It is **not a second crawler.** It calls `inspect()` (the Phase 1 walk) with three optional hooks, so it uses, unchanged: the guarded browser context (GET/HEAD only), the
private-address and host checks, the hop limits (3 pages, 2 Apply follows, 8 redirects), the deadline, the sign-in/CAPTCHA/unreachable classifier, and the Apply-link ranker.

| Hook (`inspect()` options) | What resolution does with it | Default (ordinary inspection) |
|---|---|---|
| `onForm` | records that a form was reached instead of extracting it | extract, as before |
| `observe` | collects each navigation, classification and Apply candidate as hop evidence | nothing |
| `onCandidate` | stops before a known click-tracking redirect is requested | always follow, as before |

It never clicks, types, authenticates, uploads, submits or creates anything; it knows nothing about Opportunities, Applications, agent runs or the database, so it can run on a Job before it
is anything else. Nothing in production imports it.

## Statuses

| Status | Meaning |
|---|---|
| `verified_direct` | a form was **observed** on the page (or board) the source URL itself names, on a non-aggregator site |
| `proposed_by_resolution` | reached by following one identified Apply link. A **proposal**, never verified; `needs_human_review` says when a person must look |
| `login_required` | a sign-in wall at the source or the destination |
| `unreachable` | HTTP error, DNS/connection failure or timeout |
| `blocked` | by the site (`captcha`, `anti_bot`) or by our policy (`destination_prohibited`, `redirect_limit`); see `blocked_by` |
| `unresolved` | with a reason: `no_apply_link`, `apply_is_button`, `apply_is_email`, `multiple_apply_links` (a person chooses), `too_many_steps`, `application_hosted_by_aggregator`, `tracker_redirect_requires_permission`, … |

`human_verified` is never produced here: only a person confirms.

## Tracker redirects

A known click-tracking redirect (today: Adzuna `/land/ad/`) is **not followed** unless `allowTrackerRedirects: true` is passed. See the Resolution Evaluation Plan beside the proposed Stage 1 cohort.

## Unknown redirectors and click-throughs

A cross-site Apply link (or a source URL) that is **structurally** a redirector is **refused before it is requested** and returned as `unresolved: unknown_redirector_requires_review`
(`needs_human_review: true`, with the signals): a parameter such as `url=`/`u=`/`redirect=`/`to=` whose value is itself an absolute URL, a first host label of `click`/`track`/`trk`/`redirect`…,
or a first path segment of `/redirect`, `/click`, `/track`… Never flagged: any known ATS host (they carry `gh_jid`, `utm_*` and similar all the time), the same site as the linking page, an aggregator's own pages,
and ordinary `utm_*`/`ref`/relative `next=` parameters. `/go/`, `/out/` and `/r/` are deliberately **not** signals (too common on genuine employer sites).
A person who has reviewed a host can clear it with `reviewedRedirectHosts`; it is then followed through the guard and still recorded.

An HTTP redirect chain cannot be vetted hop by hop before it is followed. A host the chain passes **through** (neither where the page was requested nor where it ended, not a known ATS, not
cleared) is recorded and flagged for review **after** the fact (`intermediate_redirect_hosts`). Known limitation: a single bounce through an unknown host with no structural signal looks like any
employer-to-ATS redirect and is followed without a flag (it is still in the hop evidence).

## Result

`destination-resolution/1`: `status`, `reason`, `source`, `destination` (`url` without its query, `host_type`, `platform`, `form_observed`, `basis`), `hops` (every page with its redirect chain, HTTP status, page kind and
Apply candidates), `apply_candidates`, `needs_human_review`, `review_reason`, `provenance` (limits, `tracker_redirects_allowed`, `clicks: 0`, `form_fields_read: 0`). No timestamps, query strings or cookies.
