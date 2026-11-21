<?php

use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStopReason;

/**
 * The worker mirrors these enums by hand (workers/browser-inspector/src/result.js; see its header for why). This is the
 * "diff them directly" check that header promises: a value added on one side and not the other fails here.
 */
function workerConstants(string $block): array
{
    $source = file_get_contents(base_path('workers/browser-inspector/src/result.js'));
    preg_match('/export const '.$block.' = Object\.freeze\(\{(.*?)\}\);/s', $source, $m);
    preg_match_all("/:\s*'([a-z_]+)'/", $m[1] ?? '', $values);

    return $values[1];
}

it('keeps the worker\'s inspection outcomes identical to InspectionOutcome', function () {
    expect(workerConstants('InspectionOutcome'))->toEqualCanonicalizing(array_column(InspectionOutcome::cases(), 'value'));
});

it('keeps the worker\'s stop reasons identical to InspectionStopReason', function () {
    expect(workerConstants('StopReason'))->toEqualCanonicalizing(array_column(InspectionStopReason::cases(), 'value'));
});

it('keeps the worker\'s label sources identical to ApplicationQuestionLabelSource, provider_schema included', function () {
    expect(workerConstants('LabelSource'))->toEqualCanonicalizing(array_column(ApplicationQuestionLabelSource::cases(), 'value'))
        ->and(workerConstants('LabelSource'))->toContain('provider_schema');
});

it('keeps the worker\'s extraction sources identical to ApplicationQuestionExtractionSource, provider_schema included', function () {
    expect(workerConstants('ExtractionSource'))->toEqualCanonicalizing(array_column(ApplicationQuestionExtractionSource::cases(), 'value'))
        ->and(workerConstants('ExtractionSource'))->toContain('provider_schema');
});

it('keeps the frontend\'s label and extraction source unions identical to the enums', function () {
    $types = file_get_contents(base_path('resources/js/types/application-inspection.ts'));
    $union = function (string $name) use ($types): array {
        preg_match('/export type '.$name.' =(.*?);\n/s', $types, $m);
        $m[1] = preg_replace('#/\*.*?\*/#s', '', $m[1] ?? '');
        preg_match_all("/'([a-z_]+)'/", $m[1], $values);

        return $values[1];
    };

    expect($union('QuestionLabelSource'))->toEqualCanonicalizing(array_column(ApplicationQuestionLabelSource::cases(), 'value'))
        ->and($union('QuestionExtractionSource'))->toEqualCanonicalizing(array_column(ApplicationQuestionExtractionSource::cases(), 'value'));
});
