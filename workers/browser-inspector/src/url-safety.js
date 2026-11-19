// Proportionate URL validation for an untrusted, externally-supplied
// application URL — not an enterprise SSRF platform. See README.md
// "Network-boundary assumptions" for what this does and does not
// protect against.
import dns from 'node:dns';
import net from 'node:net';

const PRIVATE_HOSTNAMES = new Set(['localhost', '0.0.0.0']);

// Every range an inspection must never reach: private, loopback, link-local, carrier-grade NAT, documentation and
// benchmarking ranges, multicast and reserved space, and the IPv6 forms that can embed an IPv4 address (IPv4-mapped,
// NAT64, 6to4). Kept as one list so a URL's host, a DNS answer and the address Chromium connected to are all judged by
// the same rules.
const BLOCKED = new net.BlockList();
for (const [network, prefix] of [
    ['0.0.0.0', 8],
    ['10.0.0.0', 8],
    ['100.64.0.0', 10],
    ['127.0.0.0', 8],
    ['169.254.0.0', 16],
    ['172.16.0.0', 12],
    ['192.0.0.0', 24],
    ['192.0.2.0', 24],
    ['192.168.0.0', 16],
    ['198.18.0.0', 15],
    ['198.51.100.0', 24],
    ['203.0.113.0', 24],
    ['224.0.0.0', 4],
    ['240.0.0.0', 4],
]) {
    BLOCKED.addSubnet(network, prefix, 'ipv4');
}
for (const [network, prefix] of [
    ['::', 128],
    ['::1', 128],
    ['64:ff9b::', 96],
    ['100::', 64],
    ['2001:db8::', 32],
    ['2002::', 16],
    ['fc00::', 7],
    ['fe80::', 10],
    ['fec0::', 10],
    ['ff00::', 8],
]) {
    BLOCKED.addSubnet(network, prefix, 'ipv6');
}

/**
 * @param {string} ip
 * @returns {boolean}
 */
export function isPrivateIPv4(ip) {
    return net.isIPv4(ip) && BLOCKED.check(ip, 'ipv4');
}

/**
 * @param {string} ip an IPv6 address, with or without the brackets a URL hostname keeps
 * @returns {boolean}
 */
export function isPrivateIPv6(ip) {
    const normalized = ip.toLowerCase().replace(/^\[|\]$/g, '');
    if (!net.isIPv6(normalized)) return false;

    // An IPv4-mapped address (::ffff:a.b.c.d, or ::ffff:hhhh:hhhh once a URL parser has normalised it) is judged as the
    // IPv4 address it carries: a dual-stack socket reports a public IPv4 peer this way too, so it is not blocked outright.
    const mapped = /^::ffff:(?:(\d+\.\d+\.\d+\.\d+)|([0-9a-f]{1,4}):([0-9a-f]{1,4}))$/.exec(normalized);
    if (mapped) {
        const v4 = mapped[1] ?? [parseInt(mapped[2], 16) >> 8, parseInt(mapped[2], 16) & 255, parseInt(mapped[3], 16) >> 8, parseInt(mapped[3], 16) & 255].join('.');

        return isPrivateIPv4(v4);
    }

    return BLOCKED.check(normalized, 'ipv6');
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
