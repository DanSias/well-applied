<?php

namespace App\Enums;

enum ClarificationSessionStatus: string
{
    /** The pipeline is paused waiting for the user. */
    case Pending = 'pending';
    /** The user continued after answering or reviewing. */
    case Completed = 'completed';
    /** The user chose Generate Now without answering. */
    case Skipped = 'skipped';
    /** No question cleared the value threshold, so the stage never showed. */
    case NotNeeded = 'not_needed';
}
