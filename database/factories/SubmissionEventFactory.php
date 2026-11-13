<?php

namespace Database\Factories;

use App\Enums\SubmissionEventSource;
use App\Enums\SubmissionEventType;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionEvent>
 */
class SubmissionEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'type' => SubmissionEventType::ConfirmationReceived,
            'occurred_at' => now(),
            'source' => SubmissionEventSource::User,
        ];
    }
}
