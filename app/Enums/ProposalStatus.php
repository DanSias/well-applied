<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** Replaced by a newer submission for the same question before a decision. */
    case Superseded = 'superseded';
}
