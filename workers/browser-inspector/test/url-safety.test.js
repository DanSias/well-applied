import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    validateUrlStructure,
    resolvesToPrivateAddress,
} from '../src/url-safety.js';

describe('validateUrlStructure', () => {
    test('accepts a valid https URL', () => {
        const result = validateUrlStructure(
            'https://job-boards.greenhouse.io/anthropic/jobs/123',
        );
        assert.equal(result.valid, true);
    });

    test('accepts a valid http URL', () => {
        assert.equal(
            validateUrlStructure('http://example.com/jobs/1').valid,
            true,
        );
    });

    test('rejects a malformed URL', () => {
        const result = validateUrlStructure('not a url at all');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'malformed_url');
    });

    test('rejects file: scheme', () => {
        const result = validateUrlStructure('file:///etc/passwd');
        assert.equal(result.valid, false);
        assert.match(result.reason, /unsupported_scheme/);
    });

    test('rejects javascript: scheme', () => {
        const result = validateUrlStructure('javascript:alert(1)');
        assert.equal(result.valid, false);
        assert.match(result.reason, /unsupported_scheme/);
    });

    test('rejects data: scheme', () => {
        const result = validateUrlStructure(
            'data:text/html,<script>alert(1)</script>',
        );
        assert.equal(result.valid, false);
        assert.match(result.reason, /unsupported_scheme/);
    });

    test('rejects the localhost hostname', () => {
        const result = validateUrlStructure('http://localhost:8080/');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'loopback_hostname');
    });

    test('rejects an IPv4 loopback literal', () => {
        const result = validateUrlStructure('http://127.0.0.1/');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'private_ip_literal');
    });

    test('rejects an RFC1918 private IPv4 literal', () => {
        assert.equal(validateUrlStructure('http://192.168.1.1/').valid, false);
        assert.equal(validateUrlStructure('http://10.0.0.5/').valid, false);
        assert.equal(validateUrlStructure('http://172.16.0.1/').valid, false);
    });

    test('rejects a link-local IPv4 literal', () => {
        assert.equal(
            validateUrlStructure('http://169.254.169.254/').valid,
            false,
        );
    });

    test('rejects an IPv6 loopback literal', () => {
        const result = validateUrlStructure('http://[::1]/');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'private_ip_literal');
    });
});

describe('resolvesToPrivateAddress', () => {
    test('flags an unresolvable hostname as unsafe rather than proceeding', async () => {
        const url = new URL('https://this-host-does-not-exist.invalid/');
        const result = await resolvesToPrivateAddress(url);
        assert.equal(result.safe, false);
    });
});

describe('address classification (host literals, DNS answers and connected addresses share one list)', async () => {
    const { isPrivateAddress, validateUrlStructure } = await import('../src/url-safety.js');

    test('blocks private, loopback, link-local, CGNAT, benchmarking, reserved and multicast space', () => {
        for (const ip of ['0.0.0.0', '10.1.2.3', '100.64.0.1', '100.127.255.255', '127.0.0.1', '169.254.169.254', '172.16.0.1', '172.31.255.255', '192.0.0.192', '192.168.1.1', '198.18.0.1', '198.19.255.255', '224.0.0.1', '240.0.0.1', '255.255.255.255']) {
            assert.equal(isPrivateAddress(ip), true, ip);
        }
    });

    test('allows ordinary public addresses, including those next to a blocked range', () => {
        for (const ip of ['8.8.8.8', '1.1.1.1', '93.184.216.34', '100.63.255.255', '100.128.0.1', '172.15.255.255', '172.32.0.1', '198.17.255.255', '198.20.0.1', '2606:4700::1111']) {
            assert.equal(isPrivateAddress(ip), false, ip);
        }
    });

    test('blocks IPv6 loopback, unspecified, link-local, site-local, unique-local, multicast, NAT64 and 6to4', () => {
        for (const ip of ['::1', '::', 'fe80::1', 'febf::1', 'fec0::1', 'fd00::1', 'fc00::1', 'ff02::1', '64:ff9b::7f00:1', '2002:7f00:1::', '[::1]']) {
            assert.equal(isPrivateAddress(ip), true, ip);
        }
    });

    test('judges an IPv4-mapped address as the IPv4 address it carries, in dotted and hex form', () => {
        assert.equal(isPrivateAddress('::ffff:127.0.0.1'), true);
        assert.equal(isPrivateAddress('::ffff:7f00:1'), true);
        assert.equal(isPrivateAddress('::ffff:192.168.0.4'), true);
        assert.equal(isPrivateAddress('::ffff:93.184.216.34'), false, 'a public peer on a dual-stack socket is not blocked');
        assert.equal(isPrivateAddress('::ffff:5db8:d822'), false);
    });

    test('refuses URL host literals in every form by their own address, not by a failed DNS lookup', () => {
        for (const host of ['[::ffff:127.0.0.1]', '[::ffff:7f00:1]', '[::]', '[fec0::1]', '[fe80::1]', '[64:ff9b::7f00:1]', '127.1', '0x7f.1', '2130706433', '017700000001', '198.18.0.1', '192.0.0.192']) {
            const r = validateUrlStructure(`http://${host}/`);
            assert.equal(r.valid, false, host);
            assert.equal(r.reason, 'private_ip_literal', host);
        }
    });
});
