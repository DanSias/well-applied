# Browser Inspector Worker

Well Applied's read-only Application Inspector browser worker. See
`docs/application-inspector.md` in the main repository for the full
architecture; this document is operational, not architectural.

## Purpose

Given a job-application URL, open it in a real browser, identify
whether it's a supported ATS, and extract a structured, honest
inventory of the form's fields/questions — nothing more.

## Scope

**Supports:** Greenhouse only, anonymous/read-only inspection.

**Explicit non-capabilities** — none of the following exist anywhere in
this worker's code, not as a disabled option, as an absent one:

- No form filling, typing, selecting, or file upload.
- No clicking beyond the initial page load (no `submit()`,
  `click(selector)`, or any generic action API).
- No application submission of any kind.
- No LLM, local or cloud — extraction is fully deterministic.
- No browser-agent framework (browser-use/Stagehand/Skyvern) and no
  visual/computer-use reasoning.
- No persistent browser profile, cookies, or session state.
- No Career Profile access, no Well Applied database access, no
  Laravel/PHP dependency of any kind.
- No credentials of any kind are read, stored, or required.

## Architecture boundary

This worker is independently built and independently deployed — a
Docker component, not a Laravel dependency. `src/inspect.js` is the
reusable extraction boundary, untouched since Phase 2. `src/cli.js`
remains a thin one-shot development/test wrapper around it.
`src/poll.js` is the production runtime: it claims work from Well
Applied over HTTP, calls the same `inspect()`, and reports the result
back — see "Worker runtime" below. See `docs/application-inspector.md`
for the full protocol.

## Prerequisites

- Docker + Docker Compose (for the intended runtime — see "Persistent
  deployment (normal operation)" below).
- Node.js >= 22 and `npx playwright install chromium` (for local
  development only — the Docker image already bundles a
  version-matched Chromium).

## Build

```sh
cd workers/browser-inspector
docker build -t browser-inspector:0.1.0 .
```

## Version pinning rule

The base image tag and the `playwright` npm package version **must
match exactly**. Using `mcr.microsoft.com/playwright:latest` caused a
real, reproduced version mismatch during the investigation proof of
concept (the tag resolved to an older image than the current npm
release expected, and the container failed to launch at all —
`Executable doesn't exist`). This is why the Dockerfile pins
`mcr.microsoft.com/playwright:v1.63.0-noble` alongside
`"playwright": "1.63.0"` in `package.json`, never `latest`.

## CLI usage

```sh
node src/cli.js <url>
# or, once dependencies are installed:
npm run inspect -- <url>
```

- **stdout**: exactly one JSON object (the `InspectionResult`) — never
  anything else.
- **stderr**: human-readable diagnostics only (e.g. the usage message
  on a bad invocation).
- **Exit codes**: `0` = the inspection technically completed (this
  includes an `unsupported` or `partial` `inspection_outcome` — those
  are successful executions); `1` = technical failure
  (`status: "failed"`); `2` = usage error (bad/missing argument, never
  reached the browser).

## Worker runtime

`src/poll.js` is the default container entrypoint (`Dockerfile`). It
loops: claim → inspect → report, forever, until `SIGINT`/`SIGTERM`.

```sh
node src/poll.js
# or:
npm run poll
```

### Configuration (`src/config.js`)

| Variable | Required | Default | Meaning |
|---|---|---|---|
| `CAREER_TOOLKIT_URL` | yes | — | Base URL of Well Applied, e.g. `http://192.168.1.50:8000`. The worker only ever makes **outbound** requests to this URL — it never listens on any inbound port. |
| `BROWSER_WORKER_TOKEN` | yes | — | Shared-secret bearer token. Must match Well Applied's `BROWSER_WORKER_TOKEN` env var exactly. Used only in this process's HTTP requests to Well Applied — never passed into `inspect()`, Playwright, or any browser page context. |
| `BROWSER_WORKER_IDENTITY` | no | `browser-inspector` | Sent as `worker_identity` on every claim; recorded on the `AgentRun` for audit only, not used for authorization. |
| `BROWSER_WORKER_POLL_INTERVAL_MS` | no | `5000` | Delay between claim attempts when no work is available. |
| `BROWSER_WORKER_MAX_BACKOFF_MS` | no | `60000` | Ceiling for exponential backoff after a claim/poll error (network failure, Well Applied unreachable, etc). |
| `BROWSER_WORKER_RESULT_RETRY_ATTEMPTS` | no | `3` | Attempts to deliver one result before giving up. |
| `BROWSER_WORKER_RESULT_RETRY_BACKOFF_MS` | no | `2000` | Linear backoff unit between result-delivery retries. |

Missing a required variable fails fast at startup (`loadConfig()`
throws) rather than running misconfigured.

### Loop behavior

- **Claim**: `POST {CAREER_TOOLKIT_URL}/api/worker/agent-runs/claim`. A
  `204` means no work; the loop sleeps `BROWSER_WORKER_POLL_INTERVAL_MS`
  and tries again. A `200` returns exactly `agent_run_id`,
  `application_url`, and `inspection_policy` — nothing about the
  underlying `Application`, `JobPosting`, or Career Profile.
- **Inspect**: the claimed `application_url` is passed to the
  unmodified `inspect()` from Phase 2 — the worker's extraction
  behavior is identical regardless of transport.
- **Report**: `POST {CAREER_TOOLKIT_URL}/api/worker/agent-runs/{id}/result`
  with `inspect()`'s own return value as the JSON body verbatim — it
  already matches Well Applied's validated contract field-for-field
  (see "JSON output contract" below).
- **Result-delivery retry** (`src/poll.js`'s `deliverResult()`) only
  retries a **transport-level** failure (a thrown exception — network
  unreachable, connection reset). Any response that actually came back
  from Well Applied — including a `409` stale-result rejection — is
  treated as a completed delivery and is never retried; the browser
  inspection itself is never re-run to retry a delivery. If all
  transport retries are exhausted, the result is dropped and the
  `AgentRun`'s claim lease simply expires — Well Applied's own claim
  recovery marks it `failed`/`claim_timeout` on the next poll from any
  worker, and the user can trigger a fresh retry from the UI.
- **Claim/poll errors** (Well Applied unreachable, non-2xx/204
  response) back off exponentially up to `BROWSER_WORKER_MAX_BACKOFF_MS`
  rather than retrying immediately in a tight loop.

## JSON output contract

### Success — complete

```json
{
    "status": "succeeded",
    "inspection_outcome": "complete",
    "ats": "greenhouse",
    "requested_url": "https://job-boards.greenhouse.io/anthropic/jobs/4461450008",
    "final_url": "https://job-boards.greenhouse.io/anthropic/jobs/4461450008",
    "fields": [
        {
            "position": 0,
            "external_field_id": "first_name",
            "raw_label": "First Name*",
            "label_source": "dom_label_for",
            "control_type": "text",
            "required": true,
            "options": null,
            "section": "Apply for this job",
            "extraction_source": "dom"
        }
    ],
    "warnings": [],
    "diagnostics": {
        "total_ms": 3561,
        "navigation_ms": 3329,
        "extraction_ms": 50,
        "page_title": "Job Application for Account Executive, AI Native at Anthropic",
        "timestamp": "2026-09-29T17:49:21.946Z",
        "field_count": 31,
        "unresolved_label_count": 8,
        "hidden_field_count": 0,
        "button_count": 19
    }
}
```

`position`/`external_field_id`/`raw_label`/`label_source`/
`control_type`/`required`/`options`/`section`/`extraction_source` map
directly onto Well Applied's `application_questions` table
(`ApplicationQuestion` model) — see `app/Enums/ApplicationQuestionLabelSource.php`
and `app/Enums/ApplicationQuestionExtractionSource.php` for the
authoritative enum values, hand-synced in `src/result.js` (see
"Contract alignment" below). The worker does not and cannot know
`application_id`/`agent_run_id` — those belong to Well Applied,
assigned when a future transport layer persists this result.

### Success — stopped without readable fields

A successful technical execution that did not produce fields. `inspection_outcome` says what was found and
`stop_reason` says why it stopped; neither is a failure. Since Phase 1, `unsupported` means **an application form was
reached that this inspector cannot read** — "no application form was found" is `application_not_found`.

```json
{
    "status": "succeeded",
    "inspection_outcome": "application_not_found",
    "stop_reason": "no_apply_link",
    "ats": null,
    "requested_url": "https://jobicy.com/jobs/1-platform-engineer",
    "final_url": "https://jobicy.com/jobs/1-platform-engineer",
    "resolved_url": null,
    "fields": [],
    "warnings": ["networkidle_timeout"],
    "diagnostics": {
        "total_ms": 16819,
        "navigation_ms": 16725,
        "page_title": "Remote Platform Engineer at Acme - Jobicy",
        "http_status": 200,
        "pages_visited": 1,
        "follows": 0,
        "navigation": [{ "kind": "start", "url": "https://jobicy.com/jobs/1-platform-engineer", "status": 200 }],
        "candidates": [],
        "timestamp": "..."
    }
}
```

Every URL in the result is **origin + path only**, except `resolved_url`: the one full URL (query string kept, because
e.g. a Greenhouse `gh_jid` decides which job a form is for), reported only when an application form was reached.

| `inspection_outcome` | `stop_reason` | Meaning |
|---|---|---|
| `complete` | — | A Greenhouse form was reached and its fields extracted. |
| `partial` | `no_fields_extracted` | A Greenhouse form was reached; the extractor found nothing. |
| `unsupported` | `form_without_adapter`, `ats_not_extractable` (Lever) | A form was reached but there is no extractor for it. |
| `application_not_found` | `no_apply_link` | A listing was reached; no followable Apply link (a button-only or mailto Apply adds a warning). |
| `needs_application_url` | `multiple_apply_links`, `destination_prohibited`, `destination_unreachable`, `too_many_redirects`, `too_many_steps` | The person must supply or choose a URL. |
| `authentication_required` | `login_wall` | A sign-in page. Never signed in to. |
| `blocked` | `captcha`, `anti_bot` | A challenge or "Access Denied". Never solved. |

`failed` is reserved for what the worker could not finish: a navigation timeout (including the whole time budget) or an
unexpected error.

### Failure

```json
{
    "status": "failed",
    "failure_category": "navigation_timeout",
    "failure_message": "Timed out loading Apply destination 1 of 2 (page 2 of up to 3).",
    "diagnostics": {
        "total_ms": 41012,
        "page_title": "Platform Engineer | Acme Careers",
        "pages_visited": 2,
        "follows": 1,
        "navigation": [{ "kind": "start", "url": "https://jobicy.com/jobs/1", "status": 200 }],
        "timestamp": "..."
    }
}
```

A failed result keeps the diagnostics gathered up to that point (how far navigation got, the page title). `failure_category`
is one of `navigation_timeout` / `unsupported_ats` (no longer produced) /
`unexpected_error` — a strict **subset** of Well Applied's
`AgentRunFailureCategory` enum. The fourth value, `claim_timeout`,
exists only for Well Applied's own detection of a worker that claimed
work and never reported back; it is definitionally unobservable from
inside this process and this worker never produces it.

## Contract alignment

`src/result.js` hand-copies the exact string values from Well
Applied's Phase 1 backed enums (`ApplicationQuestionLabelSource`,
`ApplicationQuestionExtractionSource`, `InspectionOutcome`,
`AgentRunFailureCategory`) rather than importing PHP or generating
code from it. For a contract this small (four enums, under 20 total
values), a hand-synced, directly-diffable copy is simpler and more
transparent than a codegen step — audit by diffing `src/result.js`
against the four `app/Enums/*.php` files directly.

## Extraction strategy

```
DOM: native <label for>/wrapping <label>
  -> aria-label / aria-labelledby attributes
  -> placeholder
  -> structural/proximity heuristic (nearest preceding readable text)
  -> unresolved
```

A single whole-page `ariaSnapshot()` call (never one call per field)
cross-checks every DOM-resolved label against the page's own
accessibility tree: `extraction_source` becomes `"both"` when they
agree, `"dom"` otherwise. ARIA is **not** used as an independent
label-recovery path for fields the DOM chain — including its proximity
fallback — already failed to resolve: no real Greenhouse or Lever field
observed during the investigation was ever rescued by ARIA after every
DOM tier had already failed (see the Lever university-selector finding
in the browser-automation investigation, where DOM and ARIA both failed
identically), and inventing a `label_source` for that unobserved case
would misrepresent provenance rather than honestly report it.

**Required-state** uses only the `required` attribute / `aria-required`
— never an asterisk-in-label heuristic. Every asterisk-marked required
field observed in the real Anthropic smoke-test capture also carried
the real `required` attribute, so the asterisk was never independently
necessary. One real, disclosed consequence: the live smoke test found
Greenhouse's résumé upload field reports `required: false` even though
it is, in practice, required — Greenhouse enforces that particular
field via client-side JavaScript rather than the native `required`
attribute, which this deterministic extractor has no way to observe.
`required: false` here means "no required attribute or aria-required
was present," not "confirmed optional."

**A real, fixed mislabeling bug, found via the live smoke test**:
Greenhouse's custom dropdown/combobox questions (Country, and several
Yes/No questions) render as a labeled visible trigger _plus_ a
separate, unlabeled underlying `<input>` that only holds the selected
value. Before a fix, that second element's proximity fallback picked up
the widget's own generic `"Select..."` prompt text and reported it as
if it were the field's real label. It's now recognized and rejected
(`src/extractors/greenhouse.js`, `GREENHOUSE_GENERIC_PROMPT_TEXT`), and
that synthetic input is honestly reported as `unresolved` instead — its
real question is already correctly captured on the separate, properly
labeled element nearby. On the real Anthropic posting this affects 8 of
31 extracted fields; see `test/fixtures/greenhouse-form.html`'s
dedicated regression case.

**A real, minor, disclosed section-context quirk**: one field
(`Country`) reported `section: "Phone"` on the live smoke test —
`nearestSectionLabel()` finds the nearest _preceding-in-DOM-order_
heading, which does not always match true visual proximity on a page
that uses CSS-based visual reordering. `section` should be read as a
best-effort hint, not a guaranteed-accurate grouping.

**Known, disclosed scope limitation**: a native radio/checkbox group
(e.g. Greenhouse's EEO gender question) is extracted as N separate
fields, one per option, each individually labeled (e.g. `"Male"`,
`"Female"`) with the shared question text only available via `section`
(e.g. `"Gender"`) — there is no synthesized single field carrying
`"Gender"` as its own label with an `options` array the way a
`<select>` is represented. This matches Well Applied's Phase 1
`ApplicationQuestion` schema (no `group` column) and the granularity
the investigation's proof of concept already validated; revisit only if
a concrete downstream need for aggregated radio-group questions
emerges.

## Detection method

Primary: the page's own hostname ends in `greenhouse.io` (after
redirects). Secondary/fallback: any `<script src>`/`<link href>` on the
page references a `greenhouse.io` domain — this covers a Greenhouse
application embedded under a company's own custom domain, since
Greenhouse's JS/CSS assets are always served from its own CDN
regardless of the page's own domain (confirmed directly against the
real Anthropic capture, `job-boards.cdn.greenhouse.io/assets/vendor-*.js`).

## Network-boundary assumptions

`src/url-safety.js` rejects, before ever launching a browser: any
scheme other than `http`/`https`; `localhost`/`0.0.0.0`; an IPv4/IPv6
loopback, RFC1918-private, or link-local literal hostname; and, after a
DNS lookup, a hostname that _resolves_ to one of those same ranges.

This is proportionate defense-in-depth, not a complete SSRF platform,
and has one explicit, accepted gap: the DNS-lookup check and the
browser's own subsequent navigation are two separate steps, so a
classic DNS-rebinding attack (the name resolves safely at check time,
then differently by the time Chromium actually connects) is not closed.
Closing that fully would require routing all navigation through an
address-pinning proxy or a network-level egress filter — real
infrastructure, deliberately not built for this pass. Flagged as a
Phase 3 follow-up if this worker is ever pointed at URLs from a less
trusted source than "already-validated `JobPosting.source_url` values a
human chose to capture."

## Docker security model

- Base image: `mcr.microsoft.com/playwright:v1.63.0-noble` (exact
  version match — see "Version pinning rule").
- Runs as the base image's built-in non-root `pwuser` with no `--privileged`, no `--cap-add=SYS_ADMIN` and no host networking.
  **Correction:** this section used to say Chromium's own sandbox was confirmed working in that setup. It was not: Playwright
  launches Chromium with `--no-sandbox` unless `chromiumSandbox: true` is passed, so in the original deployment Chromium ran
  unsandboxed, and Docker's default seccomp profile cannot start the sandbox at all (checked on the AI box). The isolated
  deployment enables it (`BROWSER_WORKER_CHROMIUM_SANDBOX=1` plus `isolation/chromium-seccomp.json`) and
  `isolation/validate-hardening.sh` verifies it is actually in force. See `isolation/README.md`.
- No Docker socket, no broad host filesystem mounts. The image has no
  writable-volume requirement at all in this pass (no screenshots or
  other diagnostic files are written to disk — see "Diagnostics"
  below).
- No Well Applied database credentials, no Well Applied API
  credentials, no unrelated API keys — this worker holds no
  credentials of any kind.
- No network service is exposed; the worker has no inbound listening
  port at all. `src/poll.js` only ever makes outbound HTTP requests to
  `CAREER_TOOLKIT_URL` — Well Applied never initiates a connection to
  this worker or the AI box it runs on.

## Diagnostics

`diagnostics` in every result is small and structured: timings, page
title, field/warning counts — never a raw DOM dump, accessibility-tree
dump, page text dump, cookies, storage, or secrets.

**No screenshots are captured in this pass.** The disposable
investigation PoC captured a screenshot on every run for exploratory
debugging; this worker deliberately does not, since nothing in Phase 2
consumes one and an unconditional per-run screenshot is exactly the
kind of diagnostic capture the architecture doc's "bounded diagnostics"
policy warns against. If a future pass adds failure-only screenshot
capture, it should write to a worker-local, size-bounded diagnostic
directory (never embed image bytes in the JSON contract) with a
predictable, collision-safe naming scheme and an explicit retention/
cleanup policy — not implemented here because nothing yet needs it.

## Tests

```sh
npm test          # everything under test/ — see below for prerequisites
```

Three kinds:

- **Deterministic, fixture-based** (`test/url-safety.test.js`,
  `test/result-contract.test.js`, `test/greenhouse-extractor.test.js`)
  — no live network dependency. `greenhouse-extractor.test.js` loads
  `test/fixtures/*.html` (synthetic, minimized reproductions of real
  observed Greenhouse structural patterns — never a copy of a real
  scraped page) into a real headless Chromium page via
  `page.setContent()`, so it requires Chromium to be available: already
  true inside the Docker image; locally, run
  `npx playwright install chromium` once first.
- **Runtime/protocol, fixture-server-based** (`test/client.test.js`,
  `test/poll.test.js`) — `client.test.js` starts a real, minimal
  `node:http` server on a random local port to stand in for Well
  Applied, so `createClient()`'s actual `fetch()` calls are exercised
  end-to-end without depending on a real Laravel server. `poll.test.js`
  drives `runOnce()`/`deliverResult()`/`runForever()` against a fake
  in-memory client object with an injectable `inspect()` stub, so no
  test here ever launches a real browser or requires the real Laravel
  server — see `src/poll.js`'s `inspectFn` parameter.
- **Live smoke test** (not an automated test file — see "AI-box
  deployment" below) — run explicitly, by hand, against a real public
  Greenhouse posting. Never part of `npm test`, exactly because a live
  third-party page is evidence, not a stable fixture.

No linter or type checker was introduced — five small, plain-ESM JS
files with JSDoc annotations didn't justify the added dependency/build
step for this pass; `node --check` on each file is used as a minimal
syntax gate.

## Normal development connectivity

For the persistent worker to claim real work, it needs to reach Well
Applied's normal dev server over the LAN — not a temporary,
manually-started one. Well Applied's `php artisan serve` (and the
`composer run dev` / `php artisan dev` multiplex that wraps it) reads
its bind address from the `SERVER_HOST` env var
(`Illuminate\Foundation\Console\ServeCommand`), defaulting to
`127.0.0.1` when unset. Setting `SERVER_HOST=0.0.0.0` in Well
Applied's own `.env` — a one-line, one-time change, no code change, no
extra process, no firewall/router change — makes the *same* `composer
run dev` session already reachable from the LAN. It takes effect the
next time that dev session is (re)started.

- **Well Applied's own URL** (browser, on the Mac): unchanged —
  `http://localhost:8000`.
- **Worker-facing URL** (what `CAREER_TOOLKIT_URL` below is set to):
  `http://<Mac's LAN IP>:8000` — find the Mac's LAN IP with
  `ipconfig getifaddr en0` (or the active interface).

This is ordinary LAN binding, not public exposure: no port forwarding,
no tunnel, nothing reachable outside the local network.

## Persistent deployment (normal operation)

> **Superseded by the isolated deployment.** The inspector follows Apply links to arbitrary employer sites, so it must not be
> run on the AI box's default Docker network. The current deployment (dedicated networks, an API relay, a host-enforced
> nftables policy, systemd-controlled fail-closed startup) is documented in **`isolation/README.md`**, including the exact
> install, start, validation and rollback steps. The instructions below describe the original, unisolated deployment and are
> kept as the rollback reference.

The worker runs as a normal, always-on Docker Compose service on the
AI box — not something started by hand for each inspection. Well
Applied never starts, stops, or has credentials for this; the worker
sits idle, polling, until Well Applied creates work (see "Worker
runtime" above). This repository (`workers/browser-inspector/`) is the
only source of truth for the worker's code — the AI-box deployment
directory is a plain, non-edited copy of it, kept in sync with
`rsync`, the same mechanism validated during Phase 3.

**Deployment layout on the AI box (current):**

| Path | Purpose |
|---|---|
| `/opt/well-applied/browser-inspector/` | the active worker (compose file, `.env`, isolation files), owned by `daniel`, mode 0750 |
| `/opt/well-applied/backups/` | previous deployment files (including the old `.env`, so mode 0700), owned by `daniel` |

The worker and its Chromium run as the unprivileged `daniel` account inside a non-root container, never as root; `/opt/well-applied`
itself is root-owned. The systemd unit is `well-applied-browser-inspector.service`; the Compose project, containers and image are
`well-applied-browser-inspector` / `well-applied-api-relay` / `well-applied-browser-inspector:local`. Install, start and rollback are in
`isolation/README.md`, which also has the one-off migration from the legacy layout.

**Legacy layout (pre-isolation, kept only as the rollback reference):** `~/career-toolkit-browser-worker/`, image
`career-toolkit-browser-worker:local`, Compose project `career-toolkit-browser-worker`. The commands in the rest of this section
describe that legacy deployment and use its paths. `CAREER_TOOLKIT_URL` and `BROWSER_WORKER_TOKEN` keep their names on purpose:
they are the worker's API contract with Well Applied and with the old `.env`.

### First-time setup

```sh
ssh <host> "mkdir -p ~/career-toolkit-browser-worker"
rsync -az --exclude node_modules --exclude .env workers/browser-inspector/ <host>:~/career-toolkit-browser-worker/
ssh <host> "cp ~/career-toolkit-browser-worker/.env.example ~/career-toolkit-browser-worker/.env"
# then edit ~/career-toolkit-browser-worker/.env on the host: set
# CAREER_TOOLKIT_URL (see "Normal development connectivity" above) and
# BROWSER_WORKER_TOKEN (must match Well Applied's own .env exactly)
```

### START

```sh
ssh <host> "cd ~/career-toolkit-browser-worker && docker compose up -d --build"
```

### STOP

```sh
ssh <host> "cd ~/career-toolkit-browser-worker && docker compose down"
```

### RESTART

```sh
ssh <host> "cd ~/career-toolkit-browser-worker && docker compose restart"
```

### STATUS

```sh
ssh <host> "cd ~/career-toolkit-browser-worker && docker compose ps"
```

Also check Well Applied's own nav-bar badge ("Browser Inspector ●
Online / last seen Ns ago") — that's the source of truth the rest of
the app actually uses, derived from the worker's own claim polls (see
`docs/application-inspector.md` "Worker presence"), not from Docker's
view of the container.

### LOGS

```sh
ssh <host> "cd ~/career-toolkit-browser-worker && docker compose logs -f"
```

### UPDATE / REBUILD (after changing `workers/browser-inspector/` in this repository)

```sh
rsync -az --exclude node_modules --exclude .env workers/browser-inspector/ <host>:~/career-toolkit-browser-worker/
ssh <host> "cd ~/career-toolkit-browser-worker && docker compose up -d --build"
```

`restart: unless-stopped` (in `compose.yaml`) means the service also
survives an AI-box reboot and restarts automatically after a crash —
without a scheduler/watchdog process, matching the same "boring,
always-there infrastructure" posture as everything else this worker
does. No port is exposed (outbound-only — see "Docker security model"
below); no privileged mode, `SYS_ADMIN`, Docker socket, or host
networking is required, same as every prior pass.

## Disposable smoke testing (not the persistent deployment)

For a one-off, throwaway validation that doesn't touch the persistent
deployment above — e.g. testing an unreleased change before updating
it — deploy to a separate, clearly disposable path and clean up
afterward:

```sh
ssh <host> "mkdir -p ~/some-clearly-disposable-path"
rsync -az --exclude node_modules workers/browser-inspector/ <host>:~/some-clearly-disposable-path/
ssh <host> "cd ~/some-clearly-disposable-path && docker build -t browser-inspector:scratch ."

# deterministic tests, inside the real image:
ssh <host> "docker run --rm --entrypoint node -v \$(pwd)/test:/app/test:ro browser-inspector:scratch --test test/*.test.js"

# one-shot live smoke test (bypasses the protocol; CLI mode only —
# find a real open posting via Greenhouse's own public API, e.g.
#  curl -s 'https://boards-api.greenhouse.io/v1/boards/<company>/jobs' — do not guess a URL):
ssh <host> "docker run --rm --entrypoint node browser-inspector:scratch src/cli.js '<live greenhouse job URL>'"

# cleanup:
ssh <host> "docker rmi browser-inspector:scratch && rm -rf ~/some-clearly-disposable-path"
```


## Navigation

`src/inspect.js` walks at most **three pages**: the start page plus at most **two Apply-link follows**.

1. The start URL comes from Well Applied's claim (`start_url`; its `start_origin` says whether a person, an earlier
   inspection, a provider or the job's source URL supplied it). It is checked before a browser is launched.
2. Each page is classified from DOM evidence (`src/page-kind.js`): **form**, **blocked**, **login**, **unreachable**, or
   **listing**. One strong signal makes a form (a resume or CV file input; name and email fields on a page about applying;
   a Greenhouse or Lever application page); weak signals (a lone text box, a search box, the word "apply", a newsletter
   signup) never do. There is no minimum field count.
3. On a listing, `src/apply-links.js` reads link destinations. The accessible name must **equal** an Apply wording ("Apply
   Now", "Apply on company site", …); failing that, an `/apply` path on the same site or a known ATS counts, and an
   embedded Greenhouse application iframe counts. Duplicates (tracking parameters, fragments, order) collapse into one.
   - **One** distinct destination: it is checked, then navigated to with a plain GET.
   - **Several**: never chosen between. The outcome is `needs_application_url` with the candidates (origin + path).
   - **None**: `application_not_found`. An Apply *button* or a `mailto:` is reported as a warning
     (`apply_is_button`, `apply_is_email`) and never pressed or opened.
4. Nothing is clicked, typed, submitted, accepted or created. Navigation is the only thing that moves the page.

Greenhouse extraction is unchanged once a Greenhouse form is reached (`src/extractors/greenhouse.js`).

## Navigation safety

Every request passes `src/guard.js`, modelled on the application-preparer's request guard:

- http(s) only, no credentials in a URL, none of the hosts `src/url-safety.js` refuses (loopback, private, link-local,
  CGNAT), and the hostname must not **resolve** to a private address (cached per host);
- **GET and HEAD only.** Every other method is aborted, subresources included (fetch, beacons, form posts). There are no
  exceptions today (`ALLOWED_NON_GET` is empty); a legitimate need would be added there with a justification and a test;
- popups are refused; downloads are off; service workers are blocked;
- **every redirect hop of a page or iframe is judged before Chromium follows it**, and a chain over 8 hops is cut off. A
  route handler only sees the first request of a chain (Chromium follows redirects without routing them again), so these
  hops are paused through Chromium's own interception (CDP `Fetch`, response stage, documents only) and failed or
  continued by the guard;
- after each page loads, the address Chromium actually connected to (`response.serverAddr()`), for the page and every hop
  before it, is checked against the private ranges before the worker reads anything. This detects a rebound connection; it
  cannot prevent the page's own script from having used it (see below).

**What this does not protect against**

- **DNS rebinding is NOT prevented.** A name can resolve to a public address when the guard checks it and to a private
  one when Chromium connects. The connected-address check runs only after the page has loaded, by which time the page's own
  script has already run: in a rebinding attack that script can **read** the internal response (it is "same origin" to the
  attacker's name) and send it out in the query string of an allowed GET. The check detects and reports such a
  connection; it does not stop the page reading it. Nothing in this process can close that gap.
- **Redirects of subresources** (images, scripts, XHR) and requests from out-of-process iframes' own sessions are not
  paused. A redirect to an internal address is a blind GET. A subresource aimed directly at a private address is blocked
  and reported (`blocked_prohibited`).
- **The page's own JavaScript runs.** Reading a page means running it, with the same exposure to the network the worker
  has.

**Required deployment control: egress filtering** (implemented in `isolation/`: a host-enforced nftables policy, a relay, and
fail-closed startup; see `isolation/README.md`). The guard is defence in depth. Before the worker is pointed at pages
other than the ones you chose yourself (and following Apply links does exactly that), run it where it cannot reach
anything private, whatever name it asks for:

- deny outbound traffic to loopback, RFC 1918 (`10/8`, `172.16/12`, `192.168/16`), link-local (`169.254/16`, including the
  cloud metadata address), CGNAT (`100.64/10`) and IPv6 unique-local, link-local and loopback (`fc00::/7`, `fe80::/10`,
  `::1`) ranges, from the worker's container or host;
- allow Well Applied itself (`CAREER_TOOLKIT_URL`) explicitly, since it is usually on that same LAN;
- on Docker, put the worker on its own network and enforce the rules in the host firewall's `DOCKER-USER` chain (or run it
  on a separate VLAN or host). A container on the default bridge reaches the LAN as the host does.

Verify it from inside the container (a request to a LAN address and to `169.254.169.254` must fail) before relying on it.

## Time budget

Well Applied sends `budget_seconds` with every claim: its lease (`BROWSER_WORKER_CLAIM_TTL`, 120 s) minus a delivery
allowance (30 s). The worker never picks its own budget. Initial values, in `src/budget.js`:

| Step | Budget |
|---|---|
| Whole inspection | 90 s (URL checks, every navigation, extraction and browser cleanup) |
| One navigation (`domcontentloaded`) | 20 s |
| Best-effort `networkidle` | 8 s (a timeout is a warning only; the page is still read) |
| Settle | 1.5 s |
| Held back for cleanup | 5 s |

Every step is cut to what is left, and a hard-stop timer closes the browser at the deadline, so three slow pages end as a
`failed` / `navigation_timeout` result carrying their diagnostics, delivered before the lease can lapse.
