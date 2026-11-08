<?php

namespace App\Support\ReadinessReview;

use App\Models\JobAnalysis;

/**
 * The one structured request of the Application Readiness Review: judge what a saved resume says against a job
 * description. The model reads the resume as submitted and cites passages; it does not see the wider career record, and
 * it never writes resume wording. Everything it says about evidence the candidate might have, and about what is
 * missing from the career record rather than from the resume, is decided afterwards from approved facts.
 */
final class ReadinessPrompt
{
    public const VERSION = 'resume-readiness-v1';

    public const MAX_REQUIREMENTS = 10;

    public const MAX_DESCRIPTION_CHARS = 9000;

    public function system(): string
    {
        return <<<'TXT'
You review one resume against one job, as a careful hiring manager would just before it is submitted. Output compact JSON only: no prose outside the fields.

Judge ONLY what the resume text actually says. The resume is given as numbered passages; do not assume the candidate's wider career history appears in it, and do not credit a passage for something it does not state.

requirements: the 6 to 10 requirements that matter most for this job, taken from the job description (finding_ids link a requirement to the supporting analysis findings when one fits). For each:
- priority: critical (the job cannot be done without it), important, or nice.
- coverage: strong (a passage clearly states it), partial (related, indirect or only part of it), or missing (no passage states it).
- depth, how the resume shows it: built (the candidate independently designed, built or owned it), supported (assisted, troubleshot, advised on, configured or worked with it), adjacent (related experience that is not the thing itself), none.
- passage_ids: the passages that support your judgment. Required for strong and partial; empty for missing. Never cite a passage for something it only resembles; a similar technology or a different kind of migration is not evidence.
- note: one short sentence on why.

hiring_argument: is the resume, as a whole, a persuasive and credible case for this job? rating persuasive, adequate or weak, with a summary of at most 60 words. Say whether the strongest evidence is what the job needs most, and whether the resume shows software the candidate actually built.
emphasis: over (experience given more room than the job values) and under (experience the job values that gets too little), at most three each, as short phrases about the resume as written.
recommendations: 3 to 5 specific edits to the resume as it stands, most valuable first. Each targets one passage (or "none" for the resume as a whole), says what to change, and names the requirements it serves. Edit, tighten, reorder or remove existing content. Do not write new resume wording. Do not recommend adding any technology, employer, credential or experience that the resume does not already show: if the job asks for something the resume lacks, say the evidence is missing and leave it to the candidate's approved record. Never suggest claiming experience in a technology the job asks for but the resume does not state.
TXT;
    }

    /**
     * @param  array<string, array{id: string, label: string, text: string}>  $passages
     */
    public function user(JobAnalysis $analysis, array $passages): string
    {
        $posting = $analysis->jobPosting;
        $findings = $analysis->findings()->get(['id', 'category', 'statement', 'emphasis'])->map(fn ($f) => [
            'id' => $f->id, 'category' => $f->category->value, 'statement' => $f->statement, 'emphasis' => $f->emphasis?->value,
        ])->all();

        return json_encode([
            'job' => [
                'company' => $posting->company, 'title' => $posting->title,
                'description' => mb_substr((string) $posting->description, 0, self::MAX_DESCRIPTION_CHARS),
                'analysis_summary' => $analysis->role_summary,
                'analysis_findings' => $findings,
            ],
            'resume_passages' => array_values(array_map(fn (array $p) => ['id' => $p['id'], 'section' => $p['label'], 'text' => $p['text']], $passages)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, string>  $passageIds
     * @param  array<int, int>  $findingIds
     * @return array<string, mixed>
     */
    public function schema(array $passageIds, array $findingIds): array
    {
        $string = fn (int $max) => ['type' => 'string', 'maxLength' => $max];
        $object = fn (array $properties) => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $passage = ['type' => 'string', 'enum' => $passageIds];
        $requirementIds = array_map(fn (int $i) => "R{$i}", range(1, self::MAX_REQUIREMENTS));

        return $object([
            'requirements' => ['type' => 'array', 'minItems' => 3, 'maxItems' => self::MAX_REQUIREMENTS, 'items' => $object([
                'id' => ['type' => 'string', 'enum' => $requirementIds],
                'text' => $string(180),
                'priority' => ['type' => 'string', 'enum' => ['critical', 'important', 'nice']],
                'finding_ids' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer', 'enum' => $findingIds ?: [0]]],
                'coverage' => ['type' => 'string', 'enum' => ['strong', 'partial', 'missing']],
                'depth' => ['type' => 'string', 'enum' => ['built', 'supported', 'adjacent', 'none']],
                'passage_ids' => ['type' => 'array', 'maxItems' => 4, 'items' => $passage],
                'note' => $string(200),
            ])],
            'hiring_argument' => $object(['rating' => ['type' => 'string', 'enum' => ['persuasive', 'adequate', 'weak']], 'summary' => $string(700)]),
            'emphasis' => $object(['over' => ['type' => 'array', 'maxItems' => 3, 'items' => $string(160)], 'under' => ['type' => 'array', 'maxItems' => 3, 'items' => $string(160)]]),
            'recommendations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => $object([
                'title' => $string(90),
                'advice' => $string(300),
                'target' => ['type' => 'string', 'enum' => [...$passageIds, 'none']],
                'requirement_ids' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'enum' => $requirementIds]],
            ])],
        ]);
    }
}
