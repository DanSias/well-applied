<?php

namespace App\Support\JobDiscovery;

/**
 * The deterministic title-family gate: a title is a target role when it names a specialization directly followed by
 * "engineer" or "developer" ("Backend Developer", "Senior Full-Stack Engineer", "Laravel Engineer"), reads
 * "Developer, Integrations", or contains one of a few explicit developer-facing phrases ("Solutions Engineer").
 * Everything lives in config/job_discovery.php (`search.title_families`).
 *
 * Whole-word matching only, and a bare "Engineer" never matches without a specialization. Whether a role is really
 * product work, and which stack it needs, is the qualification pipeline's job, not this gate's.
 */
final class TitleFamilies
{
    /** @var array<string, string> */
    private static array $compiled = [];

    /**
     * The role nouns ("engineer", "developer") that also match in the plural wherever they end a target phrase.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        $roles = config('job_discovery.search.title_families.roles');

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : ['engineer', 'developer'];
    }

    public static function matches(string $title): bool
    {
        $config = config('job_discovery.search.title_families');
        if (! is_array($config)) {
            return false;
        }

        $key = md5(serialize($config));
        self::$compiled[$key] ??= self::compile($config);

        return preg_match(self::$compiled[$key], $title) === 1;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function compile(array $config): string
    {
        $specializations = array_values(array_filter((array) ($config['specializations'] ?? []), 'is_string'));
        $roles = array_values(array_filter((array) ($config['roles'] ?? []), 'is_string'));
        if ($specializations === [] || $roles === []) {
            return '/(*FAIL)/';
        }

        // Longest first so "full stack" wins over a shorter overlapping entry; spaces in a specialization match any
        // run of spaces or hyphens, which also covers the joined spelling ("full stack" -> "fullstack", "full-stack").
        usort($specializations, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $specializationPattern = implode('|', array_map(
            fn (string $term) => implode('[\s\-]*', array_map(fn (string $word) => preg_quote($word, '~'), preg_split('/\s+/u', trim($term)) ?: [])),
            $specializations,
        ));
        // Plural too: "Software Engineers". Only the role noun is made plural, never the specialization.
        $rolePattern = implode('|', array_map(fn (string $role) => preg_quote($role, '~').'s?', $roles));

        $boundaryStart = '(?<![\p{L}\p{N}_])';
        $boundaryEnd = '(?![\p{L}\p{N}_])';
        $alternatives = [
            $boundaryStart.'(?:'.$specializationPattern.')[\s\-]+(?:'.$rolePattern.')'.$boundaryEnd,
        ];

        // "Developer, Integrations": role first, then a comma, then one of the listed specializations.
        $reversed = array_values(array_filter((array) ($config['reversed_specializations'] ?? []), 'is_string'));
        if ($reversed !== []) {
            $alternatives[] = $boundaryStart.'(?:'.$rolePattern.')\s*,\s*(?:'.implode('|', array_map(fn (string $term) => preg_quote($term, '~'), $reversed)).')'.$boundaryEnd;
        }

        foreach (array_filter((array) ($config['phrases'] ?? []), 'is_string') as $phrase) {
            $words = preg_split('/\s+/u', trim($phrase)) ?: [];
            $alternatives[] = $boundaryStart.implode('[\s\-]+', array_map(fn (string $word) => preg_quote($word, '~'), $words)).(in_array(mb_strtolower((string) end($words)), $roles, true) ? 's?' : '').$boundaryEnd;
        }

        return '~'.implode('|', $alternatives).'~iu';
    }
}
