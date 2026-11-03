<?php

use App\Support\ResumeDocument\ResumeText;

it('replaces an em dash with a clause break and normalizes surrounding spaces', function () {
    expect(ResumeText::clean("boundaries\u{2014}keeping AI out"))->toBe('boundaries, keeping AI out')
        ->and(ResumeText::clean("execution \u{2014} correcting records"))->toBe('execution, correcting records')
        ->and(ResumeText::clean("a\u{2014}b\u{2014}c"))->toBe('a, b, c');
});

it('leaves en dashes, hyphens, and clean text untouched', function () {
    expect(ResumeText::clean('Sep 2015 – Jul 2024, 10–15 hours, AI-assisted'))->toBe('Sep 2015 – Jul 2024, 10–15 hours, AI-assisted');
});

it('detects an em dash', function () {
    expect(ResumeText::containsEmDash("x\u{2014}y"))->toBeTrue()
        ->and(ResumeText::containsEmDash('x-y – z'))->toBeFalse();
});

it('wraps only the last two words in an unbreakable span and escapes the text', function () {
    $html = (string) ResumeText::html('Delivered the <b>forecasting</b> platform & saved time');

    expect($html)->toBe('Delivered the &lt;b&gt;forecasting&lt;/b&gt; platform &amp; <span class="nw">saved time</span>');
});

it('handles one-word and two-word text without a leading separator', function () {
    expect((string) ResumeText::html('Resume'))->toBe('Resume')
        ->and((string) ResumeText::html('Two words'))->toBe('<span class="nw">Two words</span>')
        ->and((string) ResumeText::html(''))->toBe('');
});

it('keeps an ordinary space between the unbreakable words so extracted text is unchanged', function () {
    $plain = strip_tags((string) ResumeText::html('A long enough sentence ending here'));

    expect($plain)->toBe('A long enough sentence ending here');
});

it('keeps the canonical profile email on the approved address', function () {
    $json = json_decode((string) file_get_contents(base_path('data/canonical-career-data.proposed.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($json['career_profile']['email'])->toBe('daniel.sias@gmail.com');
});

it('records RocketGate as ending October 2026 in the canonical data and never as current', function () {
    $json = json_decode((string) file_get_contents(base_path('data/canonical-career-data.proposed.json')), true, flags: JSON_THROW_ON_ERROR);
    $roles = collect($json['roles'])->keyBy('key');

    expect($roles['rocketgate-developer-support-engineer']['end_year'])->toBe(2026)
        ->and($roles['rocketgate-developer-support-engineer']['end_month'])->toBe(10);

    // Only the explicit correction changed: every other role keeps its dates.
    $others = $roles->except('rocketgate-developer-support-engineer');
    expect($others->pluck('end_year')->filter(fn ($y) => $y === null))->toBeEmpty();
});
