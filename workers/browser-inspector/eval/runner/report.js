// run report -> markdown. Formatting only.
export function renderRun(r) {
    const o = [`# Resolution batch: ${r.mode}`, '', `Manifest \`${r.manifest.sha256.slice(0, 12)}\` · ${r.authorisation}`, ''];
    o.push(`**${r.mode === 'plan' ? 'Plan only: nothing was run' : r.outcome.completed ? 'Completed' : r.outcome.stop ? `Stopped: ${r.outcome.stop.cause}` : 'Incomplete'}**${r.outcome.stop ? ` at ${r.outcome.stop.at_case} (${r.outcome.stop.detail})` : ''}`, '');
    const t = r.totals;
    o.push(`${t.cases} cases: ${t.attempted} attempted, ${t.planned} planned, ${t.skipped} skipped, ${t.unattempted} unattempted, ${t.refused} refused · ${t.navigations} navigations · ${t.needs_human_review} need human review`, '');
    o.push(`Limits: concurrency ${r.config.concurrency}, retries ${r.config.retries}, ${r.config.per_case_max_pages} pages per case, ${r.config.total_page_navigation_cap} navigations, ${r.config.wall_clock_cap_minutes} min, ${r.config.min_ms_between_requests_to_the_same_host} ms between requests to a host. Tracker redirects: ${r.config.allow_tracker_redirects ? `approved for ${r.config.tracker_approved_case_ids.join(', ')}` : 'off'}.`, '');
    o.push('| Case | State | Result | Review |', '|---|---|---|---|');
    for (const c of r.cases) o.push(`| ${c.id} | ${c.state} | ${c.resolution ? `${c.resolution.status}: ${c.reason}` : c.reason} | ${c.needs_human_review || c.review_reasons.length ? 'yes' : ''} |`);
    if (r.review_queue.length) o.push('', '## Human review', '', ...r.review_queue.map((q) => `- ${q.case}: ${q.reasons.join('; ') || 'flagged'}`));

    return `${o.join('\n')}\n`;
}
