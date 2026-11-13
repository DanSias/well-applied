<?php

namespace App\Support\Submissions;

use App\Enums\SubmissionEventSource;
use App\Enums\SubmissionEventType;
use App\Enums\SubmissionEvidenceKind;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a submission event: what happened to an application after it was submitted. The history is
 * append-only (the model refuses updates and deletes), so a mistake is corrected by recording `recorded_in_error`, never
 * by changing or removing anything. Only a person's own entries are accepted today, and nothing infers a confirmation
 * or an outcome. The rules:
 *
 * - the submission is re-read under a lock, so two requests cannot both write against a state only one of them saw;
 * - `occurred_at` is when it happened (kept apart from when it was recorded), cannot precede the submission, and cannot
 *   be in the future except for an interview, which may be scheduled ahead;
 * - once a submission is marked in error nothing more is recorded on it;
 * - a retry with the same idempotency key, or an identical entry (same type, time and source), returns the event already
 *   written instead of a second one;
 * - evidence, when given, is a small fixed shape: a supported `kind` and an optional `reference` (a web address for a url).
 */
final class RecordSubmissionEvent
{
    private const FUTURE_TOLERANCE_MINUTES = 5;

    /**
     * @param  array<string, mixed>|null  $evidence
     *
     * @throws SubmissionException
     */
    public function record(
        Submission $submission,
        SubmissionEventType $type,
        CarbonInterface $occurredAt,
        SubmissionEventSource $source = SubmissionEventSource::User,
        ?string $note = null,
        ?array $evidence = null,
        ?string $idempotencyKey = null,
    ): SubmissionEvent {
        $idempotencyKey = $idempotencyKey === null || trim($idempotencyKey) === '' ? null : trim($idempotencyKey);

        if (! in_array($source, SubmissionEventSource::supported(), true)) {
            throw new SubmissionException("Events from {$source->value} cannot be recorded yet.", 'source_unsupported');
        }

        $evidence = $this->evidence($evidence);
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 5000);

        return DB::transaction(function () use ($submission, $type, $occurredAt, $source, $note, $evidence, $idempotencyKey) {
            $locked = Submission::query()->lockForUpdate()->find($submission->id)
                ?? throw new SubmissionException('That submission does not exist.', 'submission_not_found');

            if ($idempotencyKey !== null) {
                $existing = $locked->events()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    if ($existing->type !== $type) {
                        throw new SubmissionException('That idempotency key was already used for a different event.', 'idempotency_key_reused');
                    }

                    return $existing;
                }
            }

            $identical = $locked->events()->where('type', $type->value)->where('source', $source->value)->where('occurred_at', $occurredAt)->first();

            if ($identical !== null) {
                return $identical;
            }

            if ($locked->events()->where('type', SubmissionEventType::RecordedInError->value)->exists()) {
                throw new SubmissionException('This application was marked as recorded in error, so nothing more can be added to it.', 'recorded_in_error');
            }

            if ($occurredAt->isBefore($locked->submitted_at)) {
                throw new SubmissionException('That cannot have happened before the application was submitted.', 'before_submission');
            }

            if ($type !== SubmissionEventType::Interview && $occurredAt->isAfter(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
                throw new SubmissionException('That cannot have happened in the future.', 'occurred_in_future');
            }

            return SubmissionEvent::query()->create([
                'submission_id' => $locked->id,
                'type' => $type,
                'occurred_at' => $occurredAt->copy()->setTimezone(config('app.timezone')),
                'source' => $source,
                'note' => $note,
                'evidence' => $evidence,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     * @return array<string, mixed>|null
     */
    private function evidence(?array $evidence): ?array
    {
        if ($evidence === null || $evidence === []) {
            return null;
        }

        $kind = is_string($evidence['kind'] ?? null) ? SubmissionEvidenceKind::tryFrom($evidence['kind']) : null;

        if ($kind === null || ! in_array($kind, SubmissionEvidenceKind::supported(), true) || array_diff(array_keys($evidence), ['kind', 'reference']) !== []) {
            throw new SubmissionException('Evidence needs a supported kind and may only add a reference.', 'evidence_invalid');
        }

        $reference = $evidence['reference'] ?? null;

        if ($reference !== null && (! is_string($reference) || strlen($reference) > 2048)) {
            throw new SubmissionException('The evidence reference must be short text.', 'evidence_invalid');
        }

        if ($kind === SubmissionEvidenceKind::Url && ! in_array(strtolower((string) parse_url((string) $reference, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new SubmissionException('A link as evidence must be a web address (http or https).', 'evidence_invalid');
        }

        return array_filter(['kind' => $kind->value, 'reference' => $reference], fn ($v) => $v !== null);
    }
}
