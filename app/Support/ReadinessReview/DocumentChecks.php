<?php

namespace App\Support\ReadinessReview;

use App\Models\ResumeVariant;

/**
 * Deterministic inspection of the rendered resume: what an automated reader gets from the PDF's text layer, and whether
 * the document is complete and well formed. Findings are either a `defect` (the document is wrong or unreadable in some
 * way) or a `suggestion` (it works, and could read better). There is no score, and nothing here predicts what any
 * particular applicant tracking system will do.
 */
final class DocumentChecks
{
    private const MONTHS = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

    /**
     * @param  array<string, array{id: string, label: string, text: string, role_id: int|null, bullet_id: int|null}>  $passages
     * @return array<int, array{kind: string, code: string, message: string}>
     */
    public function run(ResumeVariant $variant, array $passages, string $pdfText, int $pages): array
    {
        $variant->loadMissing(['careerProfile', 'experienceRoles']);
        $profile = $variant->careerProfile;
        $findings = [];
        $add = function (string $kind, string $code, string $message) use (&$findings): void {
            $findings[] = ['kind' => $kind, 'code' => $code, 'message' => $message];
        };
        $flat = fn (string $text): string => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text));
        $haystack = $flat($pdfText);

        if ($pages > 2) {
            $add('defect', 'pages', "The resume runs to {$pages} pages; two is the limit.");
        }

        if (mb_strlen(trim($pdfText)) < 300) {
            $add('defect', 'text_layer', 'Almost no text can be extracted from the PDF, so an automated reader would see an empty document.');
        } else {
            $missing = array_filter($passages, fn (array $p) => $p['bullet_id'] !== null && ! str_contains($haystack, $flat(mb_substr($p['text'], 0, 70))));

            if ($missing !== []) {
                $add('defect', 'text_missing', count($missing).' bullet'.(count($missing) === 1 ? '' : 's').' could not be found in the PDF text layer: '.implode('; ', array_map(fn (array $p) => $p['label'], array_slice($missing, 0, 3))).'.');
            }
        }

        if ($profile->email && ! str_contains(mb_strtolower($pdfText), mb_strtolower($profile->email))) {
            $add('defect', 'contact_email', 'The email address is not in the PDF text.');
        }

        if ($profile->phone && ! str_contains((string) preg_replace('/\D+/', '', $pdfText), (string) preg_replace('/\D+/', '', $profile->phone))) {
            $add('defect', 'contact_phone', 'The phone number is not in the PDF text.');
        }

        if ($profile->name && ! str_contains(mb_strtolower($pdfText), mb_strtolower($profile->name))) {
            $add('defect', 'contact_name', 'The name is not in the PDF text.');
        }

        foreach (['Technical Skills' => 'skills', 'Professional Experience' => 'experience', 'Education' => 'education'] as $heading => $section) {
            $expected = $section === 'skills' ? isset($passages['skills']) : ($section === 'education' ? isset($passages['edu1']) : true);

            if ($expected && stripos($pdfText, $heading) === false) {
                $add('defect', "heading_{$section}", "The \"{$heading}\" heading is not in the PDF text.");
            }
        }

        // Roles run newest first, and every role's years are printed.
        $roles = $variant->experienceRoles->sortBy('display_order')->values();
        $starts = $roles->map(fn ($r) => $r->start_year * 12 + ($r->start_month ?? 1))->all();
        $sorted = $starts;
        rsort($sorted);

        if ($starts !== $sorted) {
            $add('defect', 'chronology', 'The roles are not in reverse-chronological order.');
        }

        foreach ($roles as $role) {
            if (! str_contains($pdfText, (string) $role->start_year)) {
                $add('defect', 'dates', "The start year of {$role->employer_name}, {$role->display_title} is not in the PDF text.");
            }
        }

        // Where the text layer runs a label or a title straight into what follows it, a reader parsing the text may merge them.
        if (preg_match('/[a-z]('.self::MONTHS.')\s+\d{4}/', $pdfText) === 1) {
            $add('suggestion', 'text_flow_dates', 'In the extracted text a job title runs straight into its dates with no separator.');
        }

        if (preg_match('/\b[A-Z][A-Za-z&]+(?: [A-Za-z&]+)*:[A-Za-z]/', $pdfText) === 1) {
            $add('suggestion', 'text_flow_labels', 'In the extracted text a skill category label runs into its list with no space ("Label:Item").');
        }

        if (preg_match('/[\x{FFFD}\x{E000}-\x{F8FF}\x{FB00}-\x{FB06}]/u', $pdfText) === 1) {
            $add('defect', 'encoding', 'The PDF text contains private-use, replacement or ligature characters that readers may not map back to letters.');
        }

        if (str_contains($pdfText, "\u{2014}")) {
            $add('defect', 'em_dash', 'The resume contains an em dash.');
        }

        $long = array_filter($passages, fn (array $p) => $p['bullet_id'] !== null && mb_strlen($p['text']) > 320);

        if ($long !== []) {
            $add('suggestion', 'bullet_length', count($long).' bullet'.(count($long) === 1 ? ' is' : 's are').' over three lines long; shorter bullets are easier to scan.');
        }

        if (isset($passages['summary']) && str_word_count($passages['summary']['text']) > 90) {
            $add('suggestion', 'summary_length', 'The summary is over 90 words.');
        }

        return $findings;
    }
}
