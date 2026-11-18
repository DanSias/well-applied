<?php

namespace App\Enums;

/**
 * What the application-preparer's report says about the form, kept apart from AgentRunStatus (did the worker finish
 * and deliver) exactly as InspectionOutcome is kept apart for inspections. A delivered `input_required` report is a
 * successful execution. Nothing here ever means "submitted": no value of this enum is a submission, and the only way
 * to record one is a person using RecordSubmission.
 *
 * `reviewable` is the most this slice can ever say about an outcome: a person may look at it. It is never a
 * statement that the application is ready to send.
 */
enum PreparationOutcome: string
{
    /** Every required field verified. Still only a form for a person to review. */
    case ReadyForReview = 'ready_for_review';

    /** Filled as far as possible; a required answer or a person's action is missing. */
    case InputRequired = 'input_required';

    /** A human must act first: visible captcha, login, a multi-step form, or the resume upload was not verified. */
    case Blocked = 'blocked';

    /** The host or form is not supported. */
    case Unsupported = 'unsupported';

    /** Something attempted or looked like submission, or an unexpected side effect. Never reviewable as ready. */
    case SafetyFailure = 'safety_failure';

    /** Whether a person may usefully review the attempt's evidence as a prepared form. */
    public function reviewable(): bool
    {
        return in_array($this, [self::ReadyForReview, self::InputRequired, self::Blocked], true);
    }
}
