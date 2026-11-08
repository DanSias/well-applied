<?php

namespace App\Support\ReadinessReview;

use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * The exact artifact a reviewer sees: the saved variant's rows as citable passages, and the rendered PDF with the text an
 * automated reader would extract from it. The PDF is the stored one when it exists; otherwise it is composed and validated
 * the way the download does, and stored.
 *
 * Passage ids: `summary`, `skills`, `b{bullet id}` for a bullet, `edu{n}` for an education entry.
 */
final class ResumeArtifact
{
    public function __construct(
        private readonly GenerateResumeDocument $documents,
        private readonly GenerateResumePdf $renderer,
        private readonly ResumePdfValidator $validator,
        private readonly ResumePdfStore $store,
    ) {}

    /**
     * @return array{passages: array<string, array{id: string, label: string, text: string, role_id: int|null, bullet_id: int|null}>, pdf: string, pdf_text: string, pages: int, document_key: string, pdf_sha256: string}
     */
    public function read(ResumeVariant $variant): array
    {
        $variant->loadMissing(['careerProfile', 'experienceRoles.bullets', 'skillSelections', 'educationSelections']);
        $passages = [];
        $add = function (string $id, string $label, string $text, ?int $roleId = null, ?int $bulletId = null) use (&$passages): void {
            $passages[$id] = ['id' => $id, 'label' => $label, 'text' => trim($text), 'role_id' => $roleId, 'bullet_id' => $bulletId];
        };

        if (trim((string) $variant->summary) !== '') {
            $add('summary', 'Summary', (string) $variant->summary);
        }

        if ($variant->skillSelections->isNotEmpty()) {
            $add('skills', 'Skills', $variant->skillSelections->sortBy('display_order')->pluck('name')->implode(', '));
        }

        foreach ($variant->experienceRoles->sortBy('display_order') as $role) {
            foreach ($role->bullets->sortBy('display_order') as $bullet) {
                $add("b{$bullet->id}", "{$role->employer_name}, {$role->display_title}, bullet {$bullet->display_order}", $bullet->text, $role->role_id, $bullet->id);
            }
        }

        foreach ($variant->educationSelections->sortBy('display_order')->values() as $i => $education) {
            $add('edu'.($i + 1), 'Education', trim("{$education->degree} {$education->field_of_study}, {$education->institution}"));
        }

        $document = $this->documents->generate($variant);
        $pdf = $this->store->get($document);

        if ($pdf === null) {
            $pdf = $this->renderer->generate($variant);
            $this->validator->validate($pdf);
            $this->store->put($document, $pdf);
        }

        $parsed = (new PdfParser)->parseContent($pdf);

        return [
            'passages' => $passages,
            'pdf' => $pdf,
            'pdf_text' => (string) $parsed->getText(),
            'pages' => count($parsed->getPages()),
            'document_key' => $this->store->key($document),
            'pdf_sha256' => hash('sha256', $pdf),
        ];
    }
}
