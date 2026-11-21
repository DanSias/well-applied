# Jobicy and Himalayas: automation restrictions (reviewed 2026-11-21, before any live run)

**Method and its limits.** The robots files and terms pages were read once with a page-fetch tool that **summarises** what it fetches, and Himalayas' API terms were seen only through third-party summaries. Quotes are
short and approximate. **Nothing here is legal advice, and nothing was verified byte for byte.** Re-read the real pages (and ask the sites) before relying on any of it.

## Jobicy (jobicy.com)

| Source | What it says (as reported) |
|---|---|
| robots.txt, `User-agent: *` | Disallows `/go/`, `/away.php`, `/*?application_success=1`, `/app*form_id=`, filtered and paginated listings. **No rule for `/jobs/`.** No Crawl-delay. A `Content-Signal` line permits AI input, search and training. Named SEO bots are disallowed entirely. |
| Terms of service | Prohibits users from: "Harvest or scrape data (other than via our official API under its terms)"; "Bypass access controls, security, or rate limits"; "Introduce ... bots, or other malicious code". Says it "may throttle or suspend access if you exceed fair-use limits". Also says "Public Content may be crawled, indexed, cached, displayed". Says nothing about Apply links or click-through. |
| Official API | Exists and is already how discovery ingests Jobicy. A third party says its terms require credit and a link to the source. **Whether the API exposes the employer's apply URL is unverified**; the stored discovery metadata has no apply link. |

**Assessment: ambiguous.** Resolving a destination reads one listing page per job and discards its content, so it is not "harvesting data" in the ordinary sense, and robots.txt does not disallow `/jobs/`. But it is automated access to the
site's pages outside its official API, which the terms restrict, and the Apply link may be a `/go/` URL that robots.txt disallows. **Mitigation built in:** the live adapter refuses a robots-disallowed source or Apply path before requesting it
(from a dated snapshot of these rules). **Not mitigated:** the terms question itself.

## Himalayas (himalayas.app)

| Source | What it says (as reported) |
|---|---|
| robots.txt | `Allow: /`, but disallows `/apply` and pagination of `/companies`. No Crawl-delay. A `Content-Signal` line permits AI input, search and training. |
| Terms of service | Clause 30: "You may not use data mining, robots, screen scraping". Clause 93: no "bots or other automated methods to access the Services", no scripts/robots, no bypassing "access controls or use limits". **Clause 30A exempts** "use of our public API, job feeds and MCP server" (subject to their documentation and rate limits), and covers personal AI assistants acting for one person that must not collect or republish data in bulk. |
| API terms (cl. 113) | Apply to API users: stay within request limits; **every Apply action for a Himalayas job must use the API-supplied `applicationLink`** (not a direct employer or ATS link); credit Himalayas as the source. A third party reports a free key allows about 60 requests per minute. |

**Assessment: automated access to Himalayas PAGES is prohibited by its terms** (cl. 30 and 93), with an explicit exception for the API, job feeds and MCP server. A resolver that loads `himalayas.app/companies/...` pages is the activity the terms forbid.
The permitted route is the API's `applicationLink`, a different mechanism that does not exist in this codebase and that discovery's stored data does not hold.

## Consequences

- **The three-job pilot as specified (`prod-83` is Himalayas) conflicts with Himalayas' terms.** Do not run it.
- A **two-case Jobicy-only pilot** is proposed instead (`eval/cohort/resolution-pilot-jobicy-only.proposed.json`). Its remaining risk is the Jobicy terms ambiguity above, which only the owner can accept or resolve (for instance by asking Jobicy).
- Himalayas cases should be resolved through its API (if the owner wants them resolved at all) or by hand. That is a separate piece of work.
- Adzuna remains excluded (tracker redirects need their own permission, and its terms are unverified).
- The destination sites (employers and ATSs) were **not** reviewed; each has its own terms. The resolver follows at most one Apply link per page, at most 3 pages per case, paced at 10 s per host.
