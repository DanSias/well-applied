// Result-shape builders for the worker's CLI/JSON boundary.
//
// The string values below are deliberately copy-pasted, not generated,
// from Well Applied's Phase 1 backed enums, so a future audit can diff
// this file against them directly rather than trust a codegen step:
//
//   app/Enums/ApplicationQuestionLabelSource.php
//   app/Enums/ApplicationQuestionExtractionSource.php
//   app/Enums/InspectionOutcome.php
//   app/Enums/InspectionStopReason.php
//   app/Enums/AgentRunFailureCategory.php
//
// This worker never imports PHP or generates code from it — see
// README.md "Contract alignment" for why keeping this list hand-synced
// is the deliberate choice for a contract this small.

export const LabelSource = Object.freeze({
    DomLabelFor: 'dom_label_for',
    DomWrappingLabel: 'dom_wrapping_label',
    DomAriaLabel: 'dom_aria_label',
    DomAriaLabelledby: 'dom_aria_labelledby',
    DomPlaceholder: 'dom_placeholder',
    InferredProximity: 'inferred_proximity',
    // The label is the title the provider's own form schema declares; no page was read. See src/providers/ashby/.
    ProviderSchema: 'provider_schema',
    Unresolved: 'unresolved',
});

export const ExtractionSource = Object.freeze({
    Dom: 'dom',
    Aria: 'aria',
    Both: 'both',
    // The field was reported by the provider's schema, not observed in a rendered page: it says nothing about visibility.
    ProviderSchema: 'provider_schema',
});

export const InspectionOutcome = Object.freeze({
    Complete: 'complete',
    Partial: 'partial',
    AuthenticationRequired: 'authentication_required',
    MutationRequired: 'mutation_required',
    // A form was reached that this inspector cannot read (see StopReason.FormWithoutAdapter / AtsNotExtractable).
    Unsupported: 'unsupported',
    ApplicationNotFound: 'application_not_found',
    NeedsApplicationUrl: 'needs_application_url',
    Blocked: 'blocked',
});

// Why an inspection stopped where it did: the machine-readable detail behind an outcome.
export const StopReason = Object.freeze({
    NoApplyLink: 'no_apply_link',
    MultipleApplyLinks: 'multiple_apply_links',
    DestinationProhibited: 'destination_prohibited',
    DestinationUnreachable: 'destination_unreachable',
    TooManyRedirects: 'too_many_redirects',
    TooManySteps: 'too_many_steps',
    LoginWall: 'login_wall',
    Captcha: 'captcha',
    AntiBot: 'anti_bot',
    FormWithoutAdapter: 'form_without_adapter',
    AtsNotExtractable: 'ats_not_extractable',
    NoFieldsExtracted: 'no_fields_extracted',
});

// A strict SUBSET of App\Enums\AgentRunFailureCategory — the worker can
// never produce 'claim_timeout' (that value exists only for Well
// Applied's own staleness detection of a worker that claimed work and
// never reported back; from inside the worker process, that case is
// definitionally unobservable).
export const FailureCategory = Object.freeze({
    NavigationTimeout: 'navigation_timeout',
    UnsupportedAts: 'unsupported_ats',
    UnexpectedError: 'unexpected_error',
});

/**
 * @param {object} params
 * @param {string} params.ats
 * @param {string} params.requestedUrl
 * @param {string} params.finalUrl
 * @param {Array<object>} params.fields
 * @param {string[]} params.warnings
 * @param {object} params.diagnostics
 * @param {string} [params.inspectionOutcome]
 * @param {string|null} [params.stopReason] one of StopReason's values
 * @param {string|null} [params.resolvedUrl] the one FULL URL reported: where an application form was reached. The
 *   others are origin + path only (see client-side redaction in inspect.js).
 */
export function succeeded({
    ats,
    requestedUrl,
    finalUrl,
    fields,
    warnings,
    diagnostics,
    inspectionOutcome = InspectionOutcome.Complete,
    stopReason = null,
    resolvedUrl = null,
}) {
    return {
        status: 'succeeded',
        inspection_outcome: inspectionOutcome,
        stop_reason: stopReason,
        ats,
        requested_url: requestedUrl,
        final_url: finalUrl,
        resolved_url: resolvedUrl,
        fields,
        warnings,
        diagnostics,
    };
}

/**
 * An application form was reached, but this inspector has no extractor
 * for it. A successful technical execution, never a failure — and, since
 * Phase 1, never used for "no application form was found": that is
 * application_not_found. See docs/application-inspector.md "Technical
 * status vs. inspection outcome".
 *
 * @param {object} params
 * @param {string} params.requestedUrl
 * @param {string} params.finalUrl
 * @param {string[]} params.warnings
 * @param {object} params.diagnostics
 * @param {string|null} [params.ats]
 * @param {string} [params.stopReason]
 * @param {string|null} [params.resolvedUrl]
 */
export function unsupported({
    requestedUrl,
    finalUrl,
    warnings,
    diagnostics,
    ats = null,
    stopReason = StopReason.FormWithoutAdapter,
    resolvedUrl = null,
}) {
    return succeeded({
        ats,
        requestedUrl,
        finalUrl,
        fields: [],
        warnings,
        diagnostics,
        inspectionOutcome: InspectionOutcome.Unsupported,
        stopReason,
        resolvedUrl,
    });
}

/**
 * The inspection ran to a clear end without reaching a form that could be
 * read: a listing with no Apply link, an ambiguous or refused destination,
 * a barrier. Still a successful execution.
 *
 * @param {object} params
 * @param {string} params.inspectionOutcome one of InspectionOutcome's values
 * @param {string} params.stopReason one of StopReason's values
 */
export function stopped({ inspectionOutcome, stopReason, requestedUrl, finalUrl, warnings, diagnostics }) {
    return succeeded({
        ats: null,
        requestedUrl,
        finalUrl,
        fields: [],
        warnings,
        diagnostics,
        inspectionOutcome,
        stopReason,
    });
}

/**
 * @param {object} params
 * @param {string} params.failureCategory one of FailureCategory's values
 * @param {string} params.failureMessage
 * @param {object} params.diagnostics
 * @param {string[]} [params.warnings] machine-readable evidence kept with the failed run (omitted from the result when absent)
 */
export function failed({ failureCategory, failureMessage, diagnostics, warnings }) {
    return {
        status: 'failed',
        failure_category: failureCategory,
        failure_message: failureMessage,
        ...(warnings === undefined ? {} : { warnings }),
        diagnostics,
    };
}
