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

## Redirect handling and per-request policy

The guard's own address check is **cached per host**, so anything decided there is decided once per host. Approval, robots and pacing therefore do **not** live there. They are a separate layer
(`host-policy.js`, `withRequestPolicy`) evaluated for **every request**:

- A Playwright route handler registered **after** the guard's (so it runs first) decides every request and either aborts it or hands it to the guard's handler with `route.fallback()`.
- Playwright route handlers do **not** see HTTP redirect hops (Chromium follows them inside its network stack), so a second, independent CDP `Fetch` interception pauses every redirect **response** and decides the hop **before it is
  followed**. A refused hop is failed, so its target is never requested. (A regression test caught the route layer missing hops; the hop interception is what closes it.)
- For every request: the host must be approved (exact match; a redirect target is **never** added to the approved set). For every **navigation** (a document request: each page, each redirect hop, a host revisited within the case):
  the robots path rule, then pacing. Pacing is serialised, so simultaneous navigations to one host are spaced too.
- Approved hosts: the case's source host, a known ATS host, the single Apply destination the resolver chose, and hosts the owner named in the authorization. Nothing else, ever.

A refused host or path leaves the case `unresolved` for human review (`redirect_host_requires_review`, `robots_disallowed_requires_review`, `pacing_would_pass_deadline`), not a security stop. The guard's GET/HEAD-only rule, popup rule, address
check, redirect limit and everything else in `guard.js` still run on everything the policy lets through; the policy only ever refuses more.
