<?php

namespace App\Enums;

/**
 * Which durable business process a WorkflowRun accomplishes. Inspection
 * is read-only; preparation fills a form but never submits it. A new
 * workflow type is a one-line case addition, never a migration. See docs/application-inspector.md.
 */
enum WorkflowType: string
{
    case ApplicationInspection = 'application_inspection';

    /**
     * Preparing (never submitting) an application form: filling approved applicant data, attaching the resume and
     * reporting what a person must still do. See docs/application-preparation.md.
     */
    case ApplicationPreparation = 'application_preparation';
}
