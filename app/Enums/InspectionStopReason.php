<?php

namespace App\Enums;

/**
 * Why an inspection stopped where it did — the specific, machine-readable detail behind an InspectionOutcome, stored in
 * agent_runs.inspection_stop_reason. Deliberately a short list of things the worker can actually observe; the worker
 * mirrors these strings by hand (workers/browser-inspector/src/result.js), as it does the outcomes.
 */
enum InspectionStopReason: string
{
    /** A listing page with no Apply link that can be followed without clicking. */
    case NoApplyLink = 'no_apply_link';
    /** More than one distinct plausible application destination; never picked automatically. */
    case MultipleApplyLinks = 'multiple_apply_links';
    /** A start URL, link or redirect pointed somewhere the inspector must never go. */
    case DestinationProhibited = 'destination_prohibited';
    /** The destination did not load (DNS failure, error status, too many redirects). */
    case DestinationUnreachable = 'destination_unreachable';
    case TooManyRedirects = 'too_many_redirects';
    /** The page/step budget (three pages, two Apply-link follows) was used up. */
    case TooManySteps = 'too_many_steps';
    case LoginWall = 'login_wall';
    case Captcha = 'captcha';
    case AntiBot = 'anti_bot';
    /** An application form was reached, but this inspector has no extractor for it. */
    case FormWithoutAdapter = 'form_without_adapter';
    /** A recognised ATS (e.g. Lever) whose form this inspector cannot extract yet. */
    case AtsNotExtractable = 'ats_not_extractable';
    /** A form was reached and its extractor ran, but found nothing. */
    case NoFieldsExtracted = 'no_fields_extracted';
}
