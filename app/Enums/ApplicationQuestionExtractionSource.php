<?php

namespace App\Enums;

/**
 * Which representation of the page actually produced an
 * ApplicationQuestion — Dom (the primary, structural extraction), Aria
 * (the accessibility-tree cross-check, used where DOM extraction alone
 * left a field unresolved), or Both (agreement between the two). See
 * docs/application-inspector.md "DOM/ARIA-first extraction".
 */
enum ApplicationQuestionExtractionSource: string
{
    case Dom = 'dom';
    case Aria = 'aria';
    case Both = 'both';
    /** Reported by the provider's form schema rather than observed in a rendered page: it establishes no visibility. */
    case ProviderSchema = 'provider_schema';
}
