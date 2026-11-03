<?php

namespace App\Enums;

enum ProposalKind: string
{
    /** New experience that becomes a CareerFact on approval. */
    case Fact = 'fact';
    /** An explicit qualification gap; never a resume accomplishment. */
    case Limitation = 'limitation';
    /** Presentation guidance only; not an accomplishment. */
    case Preference = 'preference';
}
