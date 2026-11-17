import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { assertAllowedUrl } from '../src/url-safety.ts';

describe('url safety', () => {
    it('accepts supported ATS hosts over https', () => {
        assert.ok(assertAllowedUrl('https://jobs.lever.co/acme/1234/apply'));
        assert.ok(
            assertAllowedUrl('https://job-boards.greenhouse.io/acme/jobs/1'),
        );
    });
    for (const bad of [
        'http://jobs.lever.co/acme/1',
        'https://evil.example/jobs.lever.co',
        'https://jobs.lever.co.evil.example/x',
        'https://user:pw@jobs.lever.co/x',
        'https://jobs.lever.co:8443/x',
        'file:///etc/passwd',
        'not a url',
        'http://127.0.0.1:3000/x',
    ]) {
        it(`rejects ${bad}`, () => assert.throws(() => assertAllowedUrl(bad)));
    }
    it('accepts a local fixture server only when explicitly allowed', () => {
        assert.ok(
            assertAllowedUrl('http://127.0.0.1:3000/x', {
                allowLocalFixture: true,
            }),
        );
        assert.throws(() =>
            assertAllowedUrl('http://example.com/x', {
                allowLocalFixture: true,
            }),
        );
    });
});
