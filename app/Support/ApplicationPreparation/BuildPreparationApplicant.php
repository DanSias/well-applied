<?php

namespace App\Support\ApplicationPreparation;

use App\Models\CareerProfile;

/**
 * The applicant facts one preparation attempt may be given, from the CareerProfile and nothing else. Only what the
 * profile actually holds is sent, and only when it is plainly usable:
 *
 * - nothing is invented, guessed or defaulted: LinkedIn and current company have no source and are never sent;
 * - a name is split into first and last only when it is unambiguous (exactly two plain given-name/surname words);
 *   "Mary Jo Smith", "Smith, John", "Dr. Ann Lee", a single word or initials are left as the full name only, and
 *   the form's separate name fields are then left for a person;
 * - a value that does not look like what its key means (a malformed e-mail or URL) is left out, with the reason.
 *
 * Returns what to send and, separately, why each other key was left out, so a person can see the gap.
 */
final class BuildPreparationApplicant
{
    /** Keys the worker understands that this application has no source for. */
    private const UNSOURCED = ['linkedin_url', 'current_company'];

    /**
     * @return array{applicant: array<string, string>, omitted: array<string, string>}
     */
    public function build(CareerProfile $profile): array
    {
        $applicant = [];
        $omitted = [];

        foreach (self::UNSOURCED as $key) {
            $omitted[$key] = 'no_source';
        }

        $name = $this->clean($profile->name);
        if ($name === null) {
            $omitted['full_name'] = 'missing';
            $omitted['first_name'] = 'missing';
            $omitted['last_name'] = 'missing';
        } else {
            $applicant['full_name'] = $name;
            $parts = $this->unambiguousParts($name);
            if ($parts === null) {
                $omitted['first_name'] = 'ambiguous_name';
                $omitted['last_name'] = 'ambiguous_name';
            } else {
                [$applicant['first_name'], $applicant['last_name']] = $parts;
            }
        }

        $this->put($applicant, $omitted, 'email', $profile->email, fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 254);
        $this->put($applicant, $omitted, 'phone', $profile->phone, fn (string $v) => preg_match('/^\+?[\d\s().\-]{6,40}$/', $v) === 1 && preg_match('/\d{6,}/', preg_replace('/\D/', '', $v) ?? '') === 1);
        $this->put($applicant, $omitted, 'location', $profile->location, fn (string $v) => mb_strlen($v) <= 200);
        $this->put($applicant, $omitted, 'github_url', $profile->github_url, $this->isPlainHttpsUrl(...));
        $this->put($applicant, $omitted, 'portfolio_url', $profile->portfolio_url, $this->isPlainHttpsUrl(...));

        ksort($applicant);
        ksort($omitted);

        return ['applicant' => $applicant, 'omitted' => $omitted];
    }

    /**
     * @param  array<string, string>  $applicant
     * @param  array<string, string>  $omitted
     * @param  callable(string): bool  $valid
     */
    private function put(array &$applicant, array &$omitted, string $key, ?string $value, callable $valid): void
    {
        $value = $this->clean($value);

        if ($value === null) {
            $omitted[$key] = 'missing';
        } elseif (! $valid($value)) {
            $omitted[$key] = 'invalid';
        } else {
            $applicant[$key] = $value;
        }
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function unambiguousParts(string $name): ?array
    {
        $words = explode(' ', $name);

        if (count($words) !== 2) {
            return null;
        }

        foreach ($words as $word) {
            // A plain word of letters (with an inner hyphen or apostrophe): not an initial ("J."), a title ("Dr."), a
            // suffix, or anything with a comma or digit.
            if (preg_match('/^\p{L}[\p{L}\'’\-]*\p{L}$/u', $word) !== 1) {
                return null;
            }
        }

        return [$words[0], $words[1]];
    }

    private function isPlainHttpsUrl(string $value): bool
    {
        $parts = parse_url($value);

        return $parts !== false
            && ($parts['scheme'] ?? null) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'], $parts['pass'])
            && strlen($value) <= 2048;
    }
}
