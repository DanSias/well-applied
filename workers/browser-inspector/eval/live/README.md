# Live resolution adapter (offline-tested; never run live)

The two functions the runner core needs for a live run, and nothing else. **Not a crawler** (it drives `resolveDestination()` and the inspector's own guarded Chromium), **not part of the worker image**
(the Dockerfile copies only `src/`), **not wired into normal inspection** (nothing in `src/` imports it). Every claim here is enforced by `test/live-safety.test.js`.

| File | Role |
|---|---|
| `source-db.js` | the only production read: `SELECT source_url FROM job_postings WHERE id = ?`, read-only connection + `query_only`, no handle exposed |
| `host-policy.js` | the per-hop host policy and pacing, plugged into the guard's existing injectable address check |
| `adapter.js` | builds a fresh guard per case, launches the inspector's Chromium, calls `resolveDestination()`, closes the browser on abort |
| `robots.js`, `robots-snapshot.json` | refusal of robots-disallowed source and Apply paths, from a dated, secondhand snapshot |
| `run-live.js` | wires it to the runner core; opens the reader only after the authorization passes |
| `authorization.template.json` | a TEMPLATE (`granted: false`): refused |
| `AUTOMATION-RESTRICTIONS.md` | the Jobicy and Himalayas review and its uncertainty |

## Authorization model

A live run needs a separate authorization file, created only when the owner grants a run, quoting their statement. The runner core (`eval/runner/authorization.js`) refuses unless it matches **exactly**:
the manifest SHA-256; the set of case ids; the source hosts; each execution limit (equal to the manifest's) within hard ceilings in code; tracker redirects explicitly false; `granted`, `granted_by` and a verbatim `statement`;
and a window of at most 24 hours that includes now. A manifest never authorises a run (it is a proposal, and its hash is checked first). The CLI also requires `--supervised` and a fresh run directory.

## Redirect handling (per-hop preflight)

The guard already pauses **every document redirect at the response stage, before Chromium follows it**, and asks its address check about the target. The live adapter supplies a check that first runs the real
private-address check (a failure is final) and then refuses any host that is not approved: the case's source host, a known ATS host, the single Apply destination the resolver chose, or a host named in the authorization. So an unknown
intermediate host is **refused before it is requested**, and the case is `unresolved: redirect_host_requires_review` (not a security stop). Main-frame navigations, meta-refresh and script redirects go through the same check. Third-party
subresource hosts are refused too (a real cost: see the README's risks). The guard's GET/HEAD-only rule and everything else in `guard.js` are untouched.

Two cache-related limits are documented rather than hidden: the guard caches its per-host decision, so path rules (robots) are applied to the source URL and the Apply candidate only, and a host revisited within a case is not re-paced.
