# Resolution batch: dry-run

Manifest `048ed22ba0d2` · none: a manifest is a proposal and a dry run or plan authorises nothing

**Stopped: security_refusal** at d-09-private-apply (destination_prohibited)

10 cases: 8 attempted, 0 planned, 0 skipped, 2 unattempted, 0 refused · 12 navigations · 2 need human review

Limits: concurrency 1, retries 0, 3 pages per case, 30 navigations, 5 min, 30 ms between requests to a host. Tracker redirects: off.

| Case | State | Result | Review |
|---|---|---|---|
| d-01-jobicy-success | attempted | proposed_by_resolution: form_observed_after_following_apply_link |  |
| d-02-ambiguous-links | attempted | unresolved: multiple_apply_links | yes |
| d-03-himalayas-login | attempted | login_required: login_wall_at_destination |  |
| d-04-direct-greenhouse | attempted | verified_direct: form_observed_at_source_url |  |
| d-05-unknown-redirector | attempted | unresolved: unknown_redirector_requires_review | yes |
| d-07-no-apply-link | attempted | unresolved: no_apply_link |  |
| d-08-dead-destination | attempted | unreachable: http_404 |  |
| d-09-private-apply | attempted | blocked: destination_prohibited |  |
| d-10-after-the-stop | unattempted | batch_stopped:security_refusal |  |
| d-06-adzuna-tracker | unattempted | batch_stopped:security_refusal |  |

## Human review

- d-02-ambiguous-links: 2 distinct Apply destinations: a person chooses
- d-05-unknown-redirector: an unknown redirector or click-through (destination_in_query, tracking_path) was not followed: a person reviews it first; unknown_redirector_requires_review
