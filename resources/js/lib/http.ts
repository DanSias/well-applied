/**
 * JSON requests for the few endpoints that answer with JSON instead of an Inertia response (starting and cancelling an
 * application preparation). Laravel's CSRF protection applies to them like any other web POST: the token is the
 * XSRF-TOKEN cookie, sent back as a header, exactly as the app's own HTTP client does for Inertia requests.
 */
export function xsrfToken(cookie: string): string | null {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(cookie);

    if (match === null || match[1] === undefined) {
        return null;
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        return null;
    }
}

export type JsonReply<T> = { ok: boolean; status: number; data: T | null };

export async function postJson<T>(
    url: string,
    body?: Record<string, unknown>,
    cookie: string = typeof document === 'undefined' ? '' : document.cookie,
): Promise<JsonReply<T>> {
    const token = xsrfToken(cookie);
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
        },
        body: JSON.stringify(body ?? {}),
    });

    let data: T | null = null;

    try {
        data = (await response.json()) as T;
    } catch {
        // No JSON body (an HTML error page): the status is what matters.
    }

    return { ok: response.ok, status: response.status, data };
}
