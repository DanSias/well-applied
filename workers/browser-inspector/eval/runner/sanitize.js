// What may appear in a COMMITTED report. A resolution carries hosts and evidence; it must not carry query strings, ports, cookies, credentials,
// page text, or public URL slugs (a job title, a company name) that are not needed to read the result. Pure.
const STRUCTURAL = new Set(['jobs', 'job', 'apply', 'application', 'careers', 'career', 'details', 'detail', 'land', 'ad', 'companies', 'company', 'positions', 'position', 'view', 'embed', 'job_app', 'en-us', 'redirect', 'click', 'track']);

/** The shape of a URL path: structural words are kept; numbers, ids and slugs are not. */
export function shapePath(pathname) {
    return `/${pathname.split('/').filter(Boolean).map((seg) => {
        const s = seg.toLowerCase();
        if (STRUCTURAL.has(s)) return s;
        if (/^\d+$/.test(s)) return 'N';
        if (/^[0-9a-f-]{8,}$/.test(s)) return '<id>';

        return '<slug>';
    }).join('/')}`;
}

/** host + path shape. No scheme, port, query or fragment. A non-URL string is returned as null. */
export function shapeUrl(value) {
    try {
        const u = new URL(value);

        return `${u.hostname.toLowerCase()}${shapePath(u.pathname)}`;
    } catch {
        return null;
    }
}

/** A manifest's own `url_shape` (host/path with digits already replaced) reduced to the same form. */
export function shapeOfShape(shape) {
    if (typeof shape !== 'string') return null;
    const [host, ...rest] = shape.replace(/\?…$/, '').split('/');

    return `${host}${shapePath(`/${rest.join('/')}`)}`;
}

const keepText = (t) => (typeof t === 'string' && /^apply\b/i.test(t.trim()) && t.length <= 40 ? t.trim() : '[omitted]');

/** Deep copy of a resolution with every URL reduced to host + path shape and anchor text reduced to a plain "Apply…" or omitted. */
export function sanitizeResolution(value, key = null) {
    if (Array.isArray(value)) return value.map((v) => sanitizeResolution(v, key));
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, sanitizeResolution(v, k)]));
    if (key === 'text') return keepText(value);
    if (typeof value === 'string' && /^https?:\/\//i.test(value)) return shapeUrl(value) ?? '[url omitted]';

    return value;
}
