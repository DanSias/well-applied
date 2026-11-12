<?php

namespace App\Support\ResumeDocument;

use Illuminate\Support\Facades\Storage;

/**
 * Content-addressed cache of rendered, validated resume PDFs on the
 * local disk. The key hashes the finished ResumeDocument plus the print
 * template, so a changed template or a different document can never be
 * served a stale file, and the stored variant is never modified.
 * Preview and download read the same file, so one render serves both.
 */
final class ResumePdfStore
{
    public function key(ResumeDocument $document): string
    {
        return md5(serialize($document).'|'.md5_file(resource_path('views/resume/print.blade.php')));
    }

    public function get(ResumeDocument $document): ?string
    {
        $path = $this->path($document);

        return Storage::disk('local')->exists($path) ? Storage::disk('local')->get($path) : null;
    }

    public function put(ResumeDocument $document, string $pdf): void
    {
        Storage::disk('local')->put($this->path($document), $pdf);
    }

    /**
     * Removes a cached file: for a render that was made to be judged and then not kept.
     */
    public function forget(ResumeDocument $document): void
    {
        Storage::disk('local')->delete($this->path($document));
    }

    private function path(ResumeDocument $document): string
    {
        return 'resume-pdfs/'.$this->key($document).'.pdf';
    }
}
