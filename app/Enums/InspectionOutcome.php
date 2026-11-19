<?php

namespace App\Enums;

/**
 * What an AgentRun's inspection actually achieved — populated only when
 * AgentRun.status is Succeeded, and deliberately orthogonal to that
 * status: a technically successful execution that correctly stopped at
 * an authentication wall it was never authorized to cross is
 * status: Succeeded, inspection_outcome: AuthenticationRequired, never
 * a failure. Greenhouse-only v1 will normally only ever produce
 * Complete; the other cases exist now so this schema doesn't need to
 * change the day a second ATS is supported. See
 * docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun".
 */
enum InspectionOutcome: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case AuthenticationRequired = 'authentication_required';
    case MutationRequired = 'mutation_required';
    /**
     * A form was reached but this inspector cannot read it (no extractor for its ATS, or an unknown one). Older records
     * (before stop reasons existed) also used this for "no application form was found", which is why a stop reason is
     * needed to say which: see reachedForm().
     */
    case Unsupported = 'unsupported';
    /** A job listing was reached, but no Apply destination could be found without clicking. */
    case ApplicationNotFound = 'application_not_found';
    /** The destination is ambiguous, prohibited or unreachable: the person must supply an application URL. */
    case NeedsApplicationUrl = 'needs_application_url';
    /** A CAPTCHA, "Access Denied" or similar anti-bot barrier stopped the inspection. */
    case Blocked = 'blocked';

    /**
     * Whether an application form itself was reached. An Unsupported record only counts when its stop reason says a
     * form was there: older records carry no reason, and are not reinterpreted.
     */
    public function reachedForm(?string $stopReason): bool
    {
        return match ($this) {
            self::Complete, self::Partial => true,
            self::Unsupported => in_array($stopReason, [InspectionStopReason::FormWithoutAdapter->value, InspectionStopReason::AtsNotExtractable->value], true),
            default => false,
        };
    }
}
