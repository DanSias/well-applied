<?php

namespace App\Support\Clarification;

use App\Models\JobAnalysisFinding;
use Illuminate\Support\Str;

/**
 * Maps a job finding to a stable topic so the same subject is recognised
 * across differently worded jobs. See config/clarification.php `topics`.
 */
final class TopicCatalog
{
    /**
     * @return array{key: string, label: string}
     */
    public function resolve(JobAnalysisFinding $finding): array
    {
        $text = str_replace('_', ' ', (string) $finding->label).' '.$finding->statement;

        foreach ((array) config('clarification.topics', []) as $key => $topic) {
            foreach ($topic['patterns'] as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return ['key' => (string) $key, 'label' => (string) $topic['label']];
                }
            }
        }

        $fallback = $finding->label !== null && $finding->label !== ''
            ? str_replace('_', ' ', $finding->label)
            : Str::limit($finding->statement, 60, '');

        return ['key' => 'finding-'.Str::slug($fallback, '_'), 'label' => Str::ucfirst(trim($fallback))];
    }

    public function label(string $key): ?string
    {
        $label = config("clarification.topics.{$key}.label");

        return is_string($label) ? $label : null;
    }

    /**
     * @return array<int, string>
     */
    public function terms(string $key): array
    {
        return array_values(array_filter((array) config("clarification.topics.{$key}.terms", []), 'is_string'));
    }
}
