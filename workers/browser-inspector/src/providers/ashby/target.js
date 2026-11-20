// Turns a person-supplied Ashby application link into the two values the approved queries take, or refuses it. Pure: no DOM,
// no network. The host must be exactly jobs.ashbyhq.com over https, with no credentials or port, and the path must be exactly
// /{hosted-jobs-page-name}/{job-posting-id}[/application]. Everything else (query string, fragment) is ignored, never forwarded.

export const ASHBY_HOST = 'jobs.ashbyhq.com';
export const SLUG = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/;
export const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

const refuse = (reason) => ({ ok: false, reason });

/** @returns {{ok: true, organizationHostedJobsPageName: string, jobPostingId: string} | {ok: false, reason: string}} */
export function parseAshbyJobUrl(input) {
    if (typeof input !== 'string' || input.length === 0 || input.length > 2048) return refuse('not_a_url');
    let url;
    try {
        url = new URL(input);
    } catch {
        return refuse('not_a_url');
    }
    if (url.protocol !== 'https:') return refuse('not_https');
    if (url.hostname !== ASHBY_HOST) return refuse('not_ashby_host');
    if (url.username !== '' || url.password !== '') return refuse('credentials_in_url');
    if (url.port !== '') return refuse('port_in_url');

    const segments = url.pathname.split('/').filter((s, i, all) => !(s === '' && i === all.length - 1)).slice(1);
    if (segments.length < 2 || segments.length > 3 || (segments.length === 3 && segments[2] !== 'application')) return refuse('unexpected_path');
    const [slug, id] = segments;
    if (!SLUG.test(slug)) return refuse('invalid_page_name');
    const jobPostingId = id.toLowerCase();
    if (!UUID.test(jobPostingId)) return refuse('invalid_job_posting_id');

    return { ok: true, organizationHostedJobsPageName: slug, jobPostingId };
}
