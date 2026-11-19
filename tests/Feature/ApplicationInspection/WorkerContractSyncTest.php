<?php

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
