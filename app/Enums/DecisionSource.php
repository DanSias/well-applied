<?php

namespace App\Enums;

/** Who made a job decision. Rules never promote a job to Pursued and never override a person (see RecordJobDecision). */
enum DecisionSource: string
{
    case User = 'user';
    case Rule = 'rule';
    case Migration = 'migration';
}
