<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An edit to a resume that cannot be saved, with the reason per field (keyed like the submitted draft) so the editor can
 * show each one next to the text it concerns. Nothing is persisted when this is thrown.
 */
final class ResumeRevisionException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
