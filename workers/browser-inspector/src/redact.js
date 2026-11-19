// What may be written to logs and result messages. A URL's query string, fragment and credentials can carry tokens, and an
// error message or a rejected response can echo one back, so anything that is logged goes through here first.

/** Every URL in free text reduced to origin + path; bearer tokens and token-like parameters masked. */
export function redactText(text, maxLength = 300) {
    return String(text ?? '')
        .replace(/https?:\/\/[^\s"'<>)\]]+/gi, (u) => redactUrl(u) ?? '[url]')
        .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [redacted]')
        .replace(/\b(token|secret|password|passwd|api[_-]?key)(["']?\s*[:=]\s*["']?)[^\s"',;&]+/gi, '$1$2[redacted]')
        .slice(0, maxLength);
}

/** Origin + path only, or null when it is not a URL. */
export function redactUrl(url) {
    try {
        const u = new URL(url);

        return `${u.protocol}//${u.host}${u.pathname}`;
    } catch {
        return null;
    }
}

/** A thrown value as a short, safe message. */
export function safeMessage(error) {
    return redactText(error?.message ?? error);
}

/**
 * Well Applied's reply to a rejected result, reduced to what helps an operator and cannot echo submitted content: the
 * HTTP status, a short redacted message and the NAMES of the fields it objected to — never their values.
 */
export function describeRejection(response) {
    const body = response?.body;
    const fields = body && typeof body.errors === 'object' && body.errors !== null ? Object.keys(body.errors).slice(0, 8) : [];

    return `status ${response?.status}` + (body?.message ? `, "${redactText(body.message, 120)}"` : '') + (fields.length ? `, fields: ${fields.join(', ')}` : '');
}
