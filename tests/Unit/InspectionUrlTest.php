<?php

use App\Support\ApplicationInspection\InspectionUrl;

it('accepts an ordinary https application URL and keeps functionally necessary parameters', function () {
    expect(InspectionUrl::acceptable('https://boards.greenhouse.io/acme/jobs/123?gh_jid=123&utm_source=jobicy&gclid=x#apply'))
        ->toBe('https://boards.greenhouse.io/acme/jobs/123?gh_jid=123');
});

it('removes tracking parameters but never a Greenhouse job id, and drops an emptied query', function () {
    expect(InspectionUrl::acceptable('https://example.com/a?utm_medium=x&fbclid=y'))->toBe('https://example.com/a')
        ->and(InspectionUrl::acceptable('https://example.com/a?gh_jid=9'))->toBe('https://example.com/a?gh_jid=9');
});

it('rejects unsafe schemes, credentials and malformed values', function (string $url) {
    expect(InspectionUrl::acceptable($url))->toBeNull();
})->with([
    'javascript' => 'javascript:alert(1)',
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://example.com/x',
    'credentials' => 'https://user:pass@example.com/x',
    'user only' => 'https://user@example.com/x',
    'no host' => 'https:///path',
    'whitespace' => 'https://example.com/a b',
    'empty' => '',
]);

it('rejects loopback, private and link-local literals, intranet names and numeric host tricks', function (string $url) {
    expect(InspectionUrl::acceptable($url))->toBeNull();
})->with([
    'localhost' => 'http://localhost/x',
    'sub localhost' => 'http://app.localhost/x',
    'loopback' => 'http://127.0.0.1/x',
    'private 10' => 'http://10.0.0.5/x',
    'private 192' => 'https://192.168.1.1/x',
    'link local' => 'http://169.254.169.254/latest/meta-data',
    'ipv6 loopback' => 'http://[::1]/x',
    'decimal ip' => 'http://2130706433/x',
    'hex ip' => 'http://0x7f.0.0.1/x',
    'single label' => 'http://intranet/x',
    '.local' => 'https://printer.local/x',
    '.internal' => 'https://svc.internal/x',
    'public ip literal' => 'https://8.8.8.8/x',
]);

it('rejects a non-string and an over-long URL', function () {
    expect(InspectionUrl::acceptable(null))->toBeNull()
        ->and(InspectionUrl::acceptable(['x']))->toBeNull()
        ->and(InspectionUrl::acceptable('https://example.com/'.str_repeat('a', 2100)))->toBeNull();
});

it('redacts to origin and path, never a query string, fragment or credentials', function () {
    expect(InspectionUrl::redact('https://Example.com:8443/jobs/1?token=secret&gh_jid=1#frag'))->toBe('https://example.com:8443/jobs/1')
        ->and(InspectionUrl::redact('https://user:pw@example.com/x?a=b'))->toBe('https://example.com/x')
        ->and(InspectionUrl::redact('not a url'))->toBeNull()
        ->and(InspectionUrl::redact(null))->toBeNull();
});

it('redacts every URL inside free text such as an error message', function () {
    $text = 'page.goto: Timeout 20000ms exceeded navigating to "https://x.example.com/apply?token=abc123" (see https://y.example.org/p?q=1)';

    $redacted = InspectionUrl::redactText($text);

    expect($redacted)->not->toContain('token=abc123')->not->toContain('q=1')
        ->and($redacted)->toContain('https://x.example.com/apply')->toContain('https://y.example.org/p');
});

it('compares URLs ignoring tracking parameters, parameter order, case and a trailing slash', function () {
    expect(InspectionUrl::comparable('https://Example.com/a/?b=2&a=1&utm_source=x'))
        ->toBe(InspectionUrl::comparable('https://example.com/a?a=1&b=2'))
        ->and(InspectionUrl::comparable('https://example.com/a?gh_jid=1'))
        ->not->toBe(InspectionUrl::comparable('https://example.com/a?gh_jid=2'));
});
