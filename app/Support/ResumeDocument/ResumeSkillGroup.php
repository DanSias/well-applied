<?php

namespace App\Support\ResumeDocument;

/**
 * One category-labeled group of skill names for presentation — v1's
 * fixed category -> label map and group order live in
 * GenerateResumeDocument, not here. Skill names preserve Selection's
 * own display_order within the category; never re-sorted.
 */
final readonly class ResumeSkillGroup
{
    /**
     * @param  string[]  $skills
     * @param  string[]  $lines  The skills already broken into display lines (reference resumes). Empty means one flowing line.
     */
    public function __construct(
        public string $label,
        public array $skills,
        public array $lines = [],
    ) {}
}
