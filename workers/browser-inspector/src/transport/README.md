# Ashby schema transport (default OFF: `BROWSER_WORKER_ASHBY_SCHEMA=1`)

A plain Node HTTPS client inside the worker process. It is NOT Chromium and shares nothing with it: the browser's GET/HEAD-only guard,
sandbox, nftables policy, Docker networking and the API relay are unchanged (`test/ashby-transport-safety.test.js` checks the parts of
that which are code).

## What it does
With the flag on and a start URL that is a verified `https://jobs.ashbyhq.com/{page}/{uuid}[/application]` link, `inspect()` sends the two
approved operations (`ApiOrganizationFromHostedJobsPageName`, then `ApiJobPosting`) before any browser starts, validates both responses with
the offline adapter, maps them, and returns a `partial` result with `ats: ashby`, `provider_schema` provenance and no URL claims. Every other
link, and every other provider, takes the unchanged browser path. Both the job page and its `/application` form link are accepted: the host and
ids are verified the same way and the schema is the same.

## Limits (`LIMITS` in `ashby.js`)
Fixed destination `jobs.ashbyhq.com:443/api/non-user-graphql?op=<one of two names>`; two requests in all, one attempt per operation, no retry;
request body <= 12 000 bytes; response <= 1 000 000 bytes, counted while streaming; DNS 3 s, connect 5 s, request 10 s, each cut to what is left of the
inspection's own deadline (the same deadline the browser fallback then uses).

## Failure classes
- **availability** (service or network gave no usable answer: DNS failure, timeout, refused connection, HTTP error, non-JSON or malformed body, a changed
  schema, GraphQL errors, job not found): the worker falls back to the existing read-only browser path and records `ashby_schema_unavailable:<stage>/<reason>`.
- **security** (a prohibited DNS answer, TLS failure, any redirect, an oversized body, an address mismatch, a request the adapter would not allow, a repeated
  operation, a response about a different job or organization): NO fallback. The run fails with `unexpected_error`, a message naming the refusal, and the
  warnings `ashby_schema_security_refusal` and `ashby_schema_refused:<stage>/<reason>`.
- an unexpected exception is a failed run too (`ashby_schema_unexpected_error`), never a silent fallback.

## What is NOT enforced here
nftables allows any public TCP 443, so pinning to Ashby is by code alone. A bug in this module could reach other public hosts; the constants, the
absence of any caller-supplied destination, and the source-scan tests are the controls.

## Live activation is a separate decision
See the handoff and the milestone report: deployment gates, the unverified Ashby terms and rate limits, and the required supervised first run.
