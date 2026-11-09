# Eightfold discovery provider

One provider (`EightfoldDiscoveryProvider`, source `eightfold`) covers every employer whose public
careers site is hosted on Eightfold. It uses the unauthenticated JSON the careers pages themselves call:

- `GET https://{host}/api/pcsx/search?domain={domain}&query=…&start=…&sort_by=relevance` (10 rows/page)
- `GET https://{host}/api/pcsx/position_details?position_id=…&domain={domain}&hl=en`

These endpoints are undocumented and can change or be switched off per tenant. robots.txt on every
verified host allows `/careers` and `/api/pcsx`; Eightfold's terms were not reviewed.

## Configuration (`config/job_discovery.php`, `eightfold`)

| Key | Meaning |
|---|---|
| `enabled` (`EIGHTFOLD_DISCOVERY_ENABLED`) | Master switch. Off = not registered at all (the test suite sets it off). |
| `scheduled` (`EIGHTFOLD_DISCOVERY_SCHEDULED`) | Whether `discovery:run --due` may run it. **Default off** until a manual run has been reviewed. |
| `budget` | Pacing and per-tenant ceilings: search requests, pages per query, uncached detail fetches, attempts, backoff, Retry-After ceiling, cache lifetime. A tenant may override any key. |
| `tenants.{slug}` | `company`, `host`, `domain`, `enabled`, optional `budget`, optional `salary` mapping. |

Add an employer by adding a tenant entry, after checking that the careers page is on Eightfold and that
`/api/pcsx/search` answers for its domain. Microsoft ships configured but disabled (it returned HTTP 429
after a handful of requests); enable it with `EIGHTFOLD_MICROSOFT_ENABLED=true`.

## Behaviour

- Queries are the search profile's include terms (default profile: `software_engineering`), within the budget.
  Spellings that differ only by hyphen/space/case ("full stack" / "full-stack") return identical results, so
  only the first is sent, and the budget is spent breadth-first: page one of every distinct term, then later pages.
- For `remote_us` profiles the search also sends `location=Remote` (config `remote_location_hint`, overridable
  or disabled per tenant). On Lockheed and NVIDIA this makes the API return only remote roles (100% of sampled
  rows, vs. roughly a third without it), so result slots are not spent on on-site roles that would be discarded.
  Starbucks returns nothing with it, because it has no remote software roles. Unverified tenants (Microsoft) have no hint.
- Rows are dropped before any description fetch when the title does not match, an exclude term matches, a
  title-only role exclusion from the discovery criteria matches (`search.excluded_title_keywords`: SRE/DevOps,
  firmware, compiler), every listed location is outside the US, or (for `remote_us`) the position is on-site/hybrid.
- Source IDs are `{tenant}:{positionId}`, so the same number at two employers never collides.
- `workLocationOption: remote_local` is **not** "remote anywhere": a position is mapped to Remote only when
  a US location is listed. Listed states and whether Florida is among them are kept in metadata; no region
  restriction is inferred, so absence of Florida never excludes a position.
- Salary is normalized only where a tenant's field has known semantics (Lockheed, single location, one
  `$a - $b` range). Otherwise the raw value stays in `discovery_metadata.discovery.customFields` and
  compensation is left empty.

## Failure reporting

A failing tenant ends only its own run. The provider still returns what the other tenants produced and
reports the failure through `ReportsPartialFailure`; the orchestrator then records the attempt as
`partial` (`failure_category = partial_provider_failure`, per-tenant clauses in `failure_message`) and the
run as `partial`. Only if every tenant fails and nothing was retrieved does the attempt fail. Full
per-tenant counters are logged, and available from `EightfoldDiscoveryProvider::diagnostics()`.

Run it alone with `php artisan discovery:run --only=eightfold`.
