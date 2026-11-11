<?php

namespace App\Support\ResumeDocument;

use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Where each employer's bullets landed in an already rendered PDF, by finding every bullet's opening words in the text of
 * each page. Used only to describe a page break (an employer split across pages, a single bullet left on one side); it
 * never moves anything. Returns null, not a guess, when any bullet cannot be found in the text layer.
 */
final class BulletPlacement
{
    /**
     * @return array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null
     */
    public function of(ResumeDocument $document, string $pdfBytes): ?array
    {
        try {
            $pages = (new PdfParser)->parseContent($pdfBytes)->getPages();
        } catch (Throwable) {
            return null;
        }

        $texts = array_map(fn ($page) => $this->flat($page->getText()), $pages);
        $roles = [];

        foreach ($document->experience as $role) {
            $byPage = [];

            foreach ($role->bullets as $bullet) {
                $needle = $this->flat(mb_substr($bullet, 0, 60));
                $found = null;

                foreach ($texts as $index => $text) {
                    if ($needle !== '' && str_contains($text, $needle)) {
                        $found = $index + 1;
                        break;
                    }
                }

                if ($found === null) {
                    return null;
                }

                $byPage[$found] = ($byPage[$found] ?? 0) + 1;
            }

            ksort($byPage);
            $roles[] = ['employer' => $role->employerName, 'title' => $role->displayTitle, 'bullets' => count($role->bullets), 'by_page' => $byPage];
        }

        return $roles;
    }

    private function flat(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text));
    }
}
