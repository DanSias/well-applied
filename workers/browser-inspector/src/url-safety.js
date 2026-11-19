// Proportionate URL validation for an untrusted, externally-supplied
// application URL — not an enterprise SSRF platform. See README.md
// "Network-boundary assumptions" for what this does and does not
// protect against.
import dns from 'node:dns';

const PRIVATE_HOSTNAMES = new Set(['localhost', '0.0.0.0']);

/**
 * @param {string} ip
 * @returns {boolean}
 */
export function isPrivateIPv4(ip) {
    const parts = ip.split('.').map(Number);
    if (
        parts.length !== 4 ||
        parts.some((p) => Number.isNaN(p) || p < 0 || p > 255)
    )
        return false;
    const [a, b] = parts;
    if (a === 0) return true; // "this network" / unspecified
    if (a === 100 && b >= 64 && b <= 127) return true; // 100.64.0.0/10 carrier-grade NAT
    if (a === 127) return true; // loopback
    if (a === 10) return true; // 10.0.0.0/8
    if (a === 172 && b >= 16 && b <= 31) return true; // 172.16.0.0/12
    if (a === 192 && b === 168) return true; // 192.168.0.0/16
    if (a === 169 && b === 254) return true; // 169.254.0.0/16 link-local
    return false;
}

/**
 * @param {string} ip
 * @returns {boolean}
 */
export function isPrivateIPv6(ip) {
    // WHATWG URL keeps the brackets in url.hostname for an IPv6 literal
    // (e.g. "[::1]") — strip them before comparing.
    const normalized = ip.toLowerCase().replace(/^\[|\]$/g, '');
    if (normalized === '::1') return true; // loopback
    if (normalized.startsWith('fe80:')) return true; // link-local
    if (normalized.startsWith('fc') || normalized.startsWith('fd')) return true; // unique local fc00::/7
    return false;
}

/**
 * Structural checks only — scheme, malformed-URL rejection, and an
 * IP-literal-hostname loopback/private-range check. Does not resolve
 * DNS; see resolvesToPrivateAddress() for that.
 *
 * @param {string} rawUrl
 * @returns {{valid: true, url: URL} | {valid: false, reason: string}}
 */
export function validateUrlStructure(rawUrl, { allowHosts = new Set() } = {}) {
    let url;
    try {
        url = new URL(rawUrl);
    } catch {
        return { valid: false, reason: 'malformed_url' };
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return { valid: false, reason: `unsupported_scheme:${url.protocol}` };
    }

    if (url.username || url.password) {
        return { valid: false, reason: 'credentials_in_url' };
    }

    // Test fixtures only (see guard.js): an exact host:port the caller named, never derived from a URL being inspected.
    if (allowHosts.has(url.host.toLowerCase())) {
        return { valid: true, url };
    }

    const hostname = url.hostname.toLowerCase();
    if (PRIVATE_HOSTNAMES.has(hostname)) {
        return { valid: false, reason: 'loopback_hostname' };
    }
    if (isPrivateIPv4(hostname) || isPrivateIPv6(hostname)) {
        return { valid: false, reason: 'private_ip_literal' };
    }

    return { valid: true, url };
}

/**
 * True for an IPv4 or IPv6 address (from a DNS answer or a connection) in a private, loopback or link-local range, and
 * for the IPv4-mapped IPv6 form of one.
 *
 * @param {string} address
 * @returns {boolean}
 */
export function isPrivateAddress(address) {
    const mapped = /^::ffff:(\d+\.\d+\.\d+\.\d+)$/i.exec(address);
    if (mapped) return isPrivateIPv4(mapped[1]);
    return isPrivateIPv4(address) || isPrivateIPv6(address);
}

/**
 * Resolves the URL's hostname and rejects it if it resolves to a
 * private/loopback/link-local address. This is a real, low-cost
 * defense-in-depth check, not a complete DNS-rebinding protection —
 * the resolved address could still change between this check and
 * Playwright's own navigation (a classic TOCTOU window). Closing that
 * gap fully would require routing navigation through an
 * address-pinning proxy or an egress-filtering network boundary —
 * infrastructure this worker deliberately does not build in v1. See
 * README.md "Network-boundary assumptions".
 *
 * @param {URL} url
 * @returns {Promise<{safe: true} | {safe: false, reason: string}>}
 */
export async function resolvesToPrivateAddress(url) {
    let addresses;
    try {
        addresses = await dns.promises.lookup(url.hostname, { all: true });
    } catch {
        return { safe: false, reason: 'dns_resolution_failed' };
    }
    for (const { address } of addresses) {
        if (isPrivateAddress(address)) {
            return { safe: false, reason: 'resolved_to_private_address' };
        }
    }
    return { safe: true };
}
