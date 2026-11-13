<?php

namespace Database\Factories;

use App\Enums\SubmissionMethod;
use App\Models\JobPosting;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'method' => SubmissionMethod::Manual,
            'submitted_at' => now()->subDay(),
        ];
    }
}
