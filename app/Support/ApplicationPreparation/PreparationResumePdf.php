<?php

namespace App\Support\ApplicationPreparation;

use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use Throwable;

/**
 * The validated PDF bytes for one resume variant, by the same generate, validate, cache path the download route uses
 * (ResumeVariantPdfController), so the file handed to a worker is the file a person would download. A PDF already in
 * the content-addressed cache was validated when it was put there; anything newly rendered is validated before it is
 * cached or used. A resume that cannot be rendered or fails validation stops the dispatch with a clear reason: nothing
 * falls back to a different resume.
 */
final class PreparationResumePdf
{
    public function __construct(
        private readonly GenerateResumeDocument $documents,
        private readonly GenerateResumePdf $generator,
        private readonly ResumePdfValidator $validator,
        private readonly ResumePdfStore $store,
    ) {}

    /**
     * @throws PreparationException
     */
    public function bytes(ResumeVariant $variant): string
    {
        $document = $this->documents->generate($variant);
        $pdf = $this->store->get($document);

        if ($pdf === null) {
            try {
                $pdf = $this->generator->generate($variant);
                $this->validator->validate($pdf);
                $this->store->put($document, $pdf);
            } catch (ResumePdfPageBudgetExceededException $e) {
                throw new PreparationException("This resume renders to {$e->pageCount} pages, over the {$e->maxPages}-page limit, so it cannot be used for an application.", 'resume_over_length');
            } catch (InvalidResumePdfException) {
                throw new PreparationException('This resume\'s PDF could not be verified.', 'resume_pdf_invalid');
            } catch (Throwable) {
                throw new PreparationException('This resume could not be rendered to a PDF.', 'resume_pdf_failed');
            }
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new PreparationException('This resume\'s PDF is not a PDF.', 'resume_not_pdf');
        }
        if (strlen($pdf) > PreparationArtifacts::MAX_RESUME_BYTES) {
            throw new PreparationException('This resume\'s PDF is too large.', 'resume_too_large');
        }

        return $pdf;
    }
}
