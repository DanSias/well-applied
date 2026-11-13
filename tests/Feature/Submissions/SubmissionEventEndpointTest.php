<?php

use App\Enums\JobDisposition;
use App\Enums\SubmissionEventType;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use App\Support\Submissions\RecordSubmission;
use Illuminate\Support\Carbon;

function evProfile(): CareerProfile
{
    return CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
}

function evJob(array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => evProfile()->id, 'disposition' => JobDisposition::Pursued, 'pursued_at' => now(), ...$attributes]);
}

/** An application recorded 10 days ago on the given job (through the real writer). */
function evSubmission(?JobPosting $job = null, int $daysAgo = 10, bool $another = false): Submission
{
    $job ??= evJob();

    return app(RecordSubmission::class)->record($job, Carbon::now('America/New_York')->subDays($daysAgo)->startOfDay(), allowAnother: $another, submittedOn: Carbon::now('America/New_York')->subDays($daysAgo)->toDateString());
}

function evBody(array $overrides = []): array
{
    return [
        'idempotency_key' => 'ev-'.uniqid(),
        'type' => 'confirmation_received',
        'occurred_on' => Carbon::now('America/New_York')->subDays(8)->toDateString(),
        'timezone' => 'America/New_York',
        ...$overrides,
    ];
}

function evPost($test, Submission $submission, array $body = [])
{
    return $test->from("/opportunities/{$submission->job_posting_id}")->post(route('submissions.events.store', $submission), evBody($body));
}

function evRecord($test, Submission $submission): array
{
    return collect($test->get(route('opportunities.show', $submission->job_posting_id))->viewData('page')['props']['application']['records'])->firstWhere('id', $submission->id);
}

describe('recording an update', function () {
    it('records the update as a manual event, and sends the person back', function () {
        $submission = evSubmission();
        $date = Carbon::now('America/New_York')->subDays(8)->toDateString();

        evPost($this, $submission, ['type' => 'confirmation_received', 'occurred_on' => $date, 'note' => ' Automated email. ', 'url' => 'https://example.com/thanks'])
            ->assertRedirect("/opportunities/{$submission->job_posting_id}")->assertSessionHasNoErrors();

        $event = SubmissionEvent::sole();
        expect($event->type)->toBe(SubmissionEventType::ConfirmationReceived)
            ->and($event->source->value)->toBe('user')
            ->and($event->occurred_on)->toBe($date)
            ->and($event->note)->toBe('Automated email.')
            ->and($event->evidence)->toBe(['kind' => 'url', 'reference' => 'https://example.com/thanks'])
            ->and($event->submission_id)->toBe($submission->id);
    });

    it('records each kind of update', function (string $type) {
        $submission = evSubmission();

        evPost($this, $submission, ['type' => $type])->assertSessionHasNoErrors();

        expect(SubmissionEvent::sole()->type->value)->toBe($type);
    })->with(['confirmation_received', 'follow_up_sent', 'interview', 'rejected', 'withdrawn', 'offer_received', 'offer_accepted', 'offer_declined']);

    it('changes nothing else: not the application, not the job, no inspection record', function () {
        $job = evJob(['disposition' => JobDisposition::Shortlisted, 'pursued_at' => null]);
        $submission = evSubmission($job);
        $before = [$submission->fresh()->getAttributes(), $job->fresh()->getAttributes()];

        evPost($this, $submission);

        expect([$submission->fresh()->getAttributes(), $job->fresh()->getAttributes()])->toBe($before)
            ->and(Application::count())->toBe(0)->and(Submission::count())->toBe(1);
    });

    it('returns to the workspace or the job page when asked', function () {
        $submission = evSubmission();

        evPost($this, $submission, ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $submission->job_posting_id));
        evPost($this, $submission, ['return_to' => 'job', 'type' => 'follow_up_sent'])->assertRedirect(route('jobs.show', $submission->job_posting_id));
    });
});

describe('validation', function () {
    it('refuses what is not a recordable update, and records nothing', function (array $override, string $field) {
        evPost($this, evSubmission(), $override)->assertSessionHasErrors($field);

        expect(SubmissionEvent::count())->toBe(0);
    })->with([
        'no key' => [['idempotency_key' => ''], 'idempotency_key'],
        'no type' => [['type' => ''], 'type'],
        'unknown type' => [['type' => 'hired'], 'type'],
        'the correction is not an update type' => [['type' => 'recorded_in_error'], 'type'],
        'bad date' => [['occurred_on' => '13/11/2026'], 'occurred_on'],
        'impossible date' => [['occurred_on' => '2026-02-30'], 'occurred_on'],
        'no date' => [['occurred_on' => ''], 'occurred_on'],
        'future date' => [['occurred_on' => '2099-01-01'], 'occurred_on'],
        'bad timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'script link' => [['url' => 'javascript:alert(1)'], 'url'],
        'huge note' => [['note' => str_repeat('x', 5001)], 'note'],
    ]);

    it('refuses a date before the application was submitted, by calendar day', function () {
        $submission = evSubmission(daysAgo: 5);

        evPost($this, $submission, ['occurred_on' => Carbon::now('America/New_York')->subDays(6)->toDateString()])->assertSessionHasErrors('occurred_on');
        evPost($this, $submission, ['occurred_on' => Carbon::now('America/New_York')->subDays(5)->toDateString()])->assertSessionHasNoErrors();
    });

    it('lets an interview be dated in the future (scheduled), but nothing else', function () {
        $submission = evSubmission();
        $future = Carbon::now('America/New_York')->addDays(4)->toDateString();

        evPost($this, $submission, ['type' => 'interview', 'occurred_on' => $future])->assertSessionHasNoErrors();
        evPost($this, $submission, ['type' => 'follow_up_sent', 'occurred_on' => $future])->assertSessionHasErrors('occurred_on');
        evPost($this, $submission, ['type' => 'rejected', 'occurred_on' => $future])->assertSessionHasErrors('occurred_on');

        expect(SubmissionEvent::count())->toBe(1);
    });

    it('lets a person retry after a validation failure with the same key, and records once', function () {
        $submission = evSubmission();

        evPost($this, $submission, ['idempotency_key' => 'retry', 'occurred_on' => '2099-01-01'])->assertSessionHasErrors('occurred_on');
        expect(SubmissionEvent::count())->toBe(0);

        evPost($this, $submission, ['idempotency_key' => 'retry'])->assertSessionHasNoErrors();
        evPost($this, $submission, ['idempotency_key' => 'retry'])->assertSessionHasNoErrors();

        expect(SubmissionEvent::count())->toBe(1);
    });
});

describe('ownership', function () {
    it('404s for an unknown application and for one of another profile', function () {
        evProfile();
        $stranger = JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id, 'disposition' => JobDisposition::Pursued]);
        $theirs = Submission::factory()->create(['job_posting_id' => $stranger->id, 'submitted_at' => now()->subDays(5)]);

        $this->post(route('submissions.events.store', 999999), evBody())->assertNotFound();
        $this->post('/submissions/abc/events', evBody())->assertNotFound();
        $this->post(route('submissions.events.store', $theirs), evBody())->assertNotFound();
        $this->post(route('submissions.correction.store', $theirs), ['idempotency_key' => 'k', 'confirmed' => true])->assertNotFound();

        expect(SubmissionEvent::count())->toBe(0);
    });
});

describe('retries and duplicates', function () {
    it('records once for a repeated key, however many times it is pressed', function () {
        $submission = evSubmission();

        foreach (range(1, 4) as $_) {
            evPost($this, $submission, ['idempotency_key' => 'same', 'note' => 'again']);
        }

        expect(SubmissionEvent::count())->toBe(1);
    });

    it('refuses a key already used for a different kind of update', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['idempotency_key' => 'k', 'type' => 'follow_up_sent']);

        evPost($this, $submission, ['idempotency_key' => 'k', 'type' => 'interview'])->assertSessionHasErrors('submission');

        expect(SubmissionEvent::count())->toBe(1);
    });

    it('treats an identical update sent with a fresh key as the same one', function () {
        $submission = evSubmission();
        $body = ['type' => 'follow_up_sent', 'occurred_on' => Carbon::now('America/New_York')->subDays(3)->toDateString()];

        evPost($this, $submission, $body + ['idempotency_key' => 'a']);
        evPost($this, $submission, $body + ['idempotency_key' => 'b']);

        expect(SubmissionEvent::count())->toBe(1);
    });

    it('still allows repeats on different days: several follow-ups and interviews', function () {
        $submission = evSubmission();

        foreach ([8, 6, 4] as $days) {
            evPost($this, $submission, ['type' => 'follow_up_sent', 'occurred_on' => Carbon::now('America/New_York')->subDays($days)->toDateString()]);
        }
        foreach ([5, 2] as $days) {
            evPost($this, $submission, ['type' => 'interview', 'occurred_on' => Carbon::now('America/New_York')->subDays($days)->toDateString()]);
        }

        $record = evRecord($this, $submission);
        expect($record['follow_ups'])->toBe(3)->and($record['interviews'])->toBe(2)->and($record['phase'])->toBe('interviewing')->and(count($record['events']))->toBe(5);
    });
});

describe('the status it produces', function () {
    it('is Applied with no updates, and offers the ordinary updates', function () {
        $record = evRecord($this, evSubmission());

        expect($record['phase'])->toBe('submitted')->and($record['events'])->toBe([])->and($record['ended'])->toBeFalse()->and($record['in_error'])->toBeFalse()
            ->and(collect($record['allowed_updates'])->pluck('value')->all())->toBe(['confirmation_received', 'follow_up_sent', 'interview', 'offer_received', 'rejected', 'withdrawn']);
    });

    it('moves through confirmation, interviews and an offer, and then to accepted or declined', function (string $final, string $phase, string $label) {
        $submission = evSubmission();

        foreach ([['confirmation_received', 8], ['follow_up_sent', 7], ['interview', 6], ['offer_received', 4]] as [$type, $days]) {
            evPost($this, $submission, ['type' => $type, 'occurred_on' => Carbon::now('America/New_York')->subDays($days)->toDateString()]);
        }

        $offered = evRecord($this, $submission);
        expect($offered['phase'])->toBe('offer_received')->and($offered['confirmed'])->toBeTrue()
            ->and(collect($offered['allowed_updates'])->pluck('value')->all())->toBe(['follow_up_sent', 'interview', 'offer_accepted', 'offer_declined', 'rejected', 'withdrawn']);

        evPost($this, $submission, ['type' => $final, 'occurred_on' => Carbon::now('America/New_York')->subDays(2)->toDateString()]);

        $done = evRecord($this, $submission);
        expect($done['phase'])->toBe($phase)->and($done['phase_label'])->toBe($label)->and($done['ended'])->toBeTrue()->and($done['active'])->toBeFalse()->and($done['allowed_updates'])->toBe([]);
    })->with([['offer_accepted', 'offer_accepted', 'Offer accepted'], ['offer_declined', 'offer_declined', 'Offer declined']]);

    it('ends on a rejection or a withdrawal', function (string $type, string $label) {
        $submission = evSubmission();

        evPost($this, $submission, ['type' => $type]);

        expect(evRecord($this, $submission))->toMatchArray(['phase_label' => $label, 'ended' => true, 'active' => false, 'allowed_updates' => []]);
    })->with([['rejected', 'Rejected'], ['withdrawn', 'Withdrawn']]);

    it('shows the Applications page and list with the same derived status', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['type' => 'interview']);

        $row = collect($this->get('/applications')->viewData('page')['props']['applications'])->first();
        $indicator = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->firstWhere('id', $submission->job_posting_id)['submission'];

        expect($row['phase_label'])->toBe('Interviewing')->and($indicator['label'])->toBe('Interviewing');
    });
});

describe('once the application has ended', function () {
    it('refuses further ordinary updates, keeps the history, and does not reopen it', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['type' => 'interview', 'occurred_on' => Carbon::now('America/New_York')->subDays(6)->toDateString()]);
        evPost($this, $submission, ['type' => 'rejected', 'occurred_on' => Carbon::now('America/New_York')->subDays(4)->toDateString()]);

        foreach (['follow_up_sent', 'interview', 'offer_received', 'confirmation_received', 'withdrawn', 'rejected'] as $type) {
            evPost($this, $submission, ['type' => $type, 'occurred_on' => Carbon::now('America/New_York')->subDays(2)->toDateString()])->assertSessionHasErrors('submission');
            expect(session('errors')->get('reason'))->toBe(['already_ended']);
        }

        $record = evRecord($this, $submission);
        expect($record['phase'])->toBe('rejected')->and(count($record['events']))->toBe(2)->and(Submission::count())->toBe(1);
    });

    it('still lets history that happened before the ending be filled in', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['type' => 'rejected', 'occurred_on' => Carbon::now('America/New_York')->subDays(3)->toDateString()]);

        evPost($this, $submission, ['type' => 'interview', 'occurred_on' => Carbon::now('America/New_York')->subDays(5)->toDateString()])->assertSessionHasNoErrors();

        expect(evRecord($this, $submission)['phase'])->toBe('rejected');
    });

    it('does not create another application on its own, and another needs the explicit workflow', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['type' => 'rejected']);

        expect(Submission::count())->toBe(1);
        app(RecordSubmission::class)->record($submission->job_posting_id, now()->startOfDay());
        expect(Submission::count())->toBe(2);
    });

    it('refuses two different endings, even if sent one after another', function () {
        $submission = evSubmission();

        evPost($this, $submission, ['type' => 'withdrawn', 'occurred_on' => Carbon::now('America/New_York')->subDays(4)->toDateString()])->assertSessionHasNoErrors();
        evPost($this, $submission, ['type' => 'rejected', 'occurred_on' => Carbon::now('America/New_York')->subDays(6)->toDateString()])->assertSessionHasErrors('submission');

        expect(evRecord($this, $submission)['phase'])->toBe('withdrawn');
    });
});

describe('marking an application as recorded in error', function () {
    function evCorrect($test, Submission $submission, array $body = [])
    {
        return $test->from("/opportunities/{$submission->job_posting_id}")->post(route('submissions.correction.store', $submission), ['idempotency_key' => 'fix-'.uniqid(), 'confirmed' => true, ...$body]);
    }

    it('needs an explicit confirmation', function () {
        $submission = evSubmission();

        evCorrect($this, $submission, ['confirmed' => false])->assertSessionHasErrors('confirmed');
        evCorrect($this, $submission, ['confirmed' => null])->assertSessionHasErrors('confirmed');

        expect(SubmissionEvent::count())->toBe(0);
    });

    it('records the correction as an event with an optional reason, and deletes nothing', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['type' => 'confirmation_received']);

        evCorrect($this, $submission, ['reason' => 'I only saved a draft.'])->assertSessionHasNoErrors();

        $record = evRecord($this, $submission);
        expect(Submission::count())->toBe(1)->and(SubmissionEvent::count())->toBe(2)
            ->and($record['phase'])->toBe('recorded_in_error')->and($record['in_error'])->toBeTrue()->and($record['active'])->toBeFalse()
            ->and($record['allowed_updates'])->toBe([])->and($record['confirmed'])->toBeFalse()
            ->and(collect($record['events'])->first())->toMatchArray(['label' => 'Recorded in error', 'note' => 'I only saved a draft.']);
    });

    it('is a single correction however many times it is sent', function () {
        $submission = evSubmission();

        evCorrect($this, $submission, ['idempotency_key' => 'same']);
        evCorrect($this, $submission, ['idempotency_key' => 'same']);
        evCorrect($this, $submission);

        expect(SubmissionEvent::where('type', 'recorded_in_error')->count())->toBe(1);
    });

    it('allows no further updates', function () {
        $submission = evSubmission();
        evCorrect($this, $submission);

        evPost($this, $submission, ['type' => 'follow_up_sent'])->assertSessionHasErrors('submission');

        expect(session('errors')->get('reason'))->toBe(['recorded_in_error'])->and(SubmissionEvent::count())->toBe(1);
    });

    it('can correct an application that already ended', function () {
        $submission = evSubmission();
        evPost($this, $submission, ['type' => 'rejected']);

        evCorrect($this, $submission)->assertSessionHasNoErrors();

        expect(evRecord($this, $submission)['phase'])->toBe('recorded_in_error');
    });

    it('leaves the workspace, the list and the counts, and stays in the historical group', function () {
        $job = evJob();
        $mistake = evSubmission($job, 12);
        $good = evSubmission($job, 3, another: true);
        evCorrect($this, $mistake);

        $props = $this->get('/applications')->viewData('page')['props'];
        $indicator = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->firstWhere('id', $job->id)['submission'];

        expect(collect($props['applications'])->pluck('id')->all())->toBe([$good->id])
            ->and(collect($props['in_error'])->pluck('id')->all())->toBe([$mistake->id])
            ->and($props['counts'])->toBe(['recorded' => 1, 'in_progress' => 1])
            ->and($indicator['id'])->toBe($good->id)->and($indicator['count'])->toBe(1);
    });

    it('does not change the job\'s disposition', function () {
        $job = evJob();
        $submission = evSubmission($job);

        evCorrect($this, $submission);

        expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
    });
});

describe('several applications to one job', function () {
    it('keeps a separate history and status for each', function () {
        $job = evJob();
        $first = evSubmission($job, 20);
        evPost($this, $first, ['type' => 'rejected', 'occurred_on' => Carbon::now('America/New_York')->subDays(15)->toDateString()]);
        $second = evSubmission($job, 8);
        evPost($this, $second, ['type' => 'confirmation_received', 'occurred_on' => Carbon::now('America/New_York')->subDays(7)->toDateString()]);
        evPost($this, $second, ['type' => 'interview', 'occurred_on' => Carbon::now('America/New_York')->subDays(3)->toDateString()]);

        $one = evRecord($this, $first);
        $two = evRecord($this, $second);

        expect($one['phase'])->toBe('rejected')->and(collect($one['events'])->pluck('label')->all())->toBe(['Rejected'])
            ->and($two['phase'])->toBe('interviewing')->and(collect($two['events'])->pluck('label')->all())->toBe(['Interview', 'Confirmation received'])
            ->and(SubmissionEvent::where('submission_id', $first->id)->count())->toBe(1);
    });

    it('lists the newest application first, each with its own events', function () {
        $job = evJob();
        $old = evSubmission($job, 20);
        $new = evSubmission($job, 4, another: true);

        $records = $this->get(route('opportunities.show', $job))->viewData('page')['props']['application']['records'];

        expect(collect($records)->pluck('id')->all())->toBe([$new->id, $old->id]);
    });
});

describe('the date of an update', function () {
    it('shows the day that was entered, in every timezone it could have been entered from', function (string $tz) {
        $submission = evSubmission(daysAgo: 20);
        $day = Carbon::now('UTC')->subDays(6)->toDateString();

        evPost($this, $submission, ['occurred_on' => $day, 'timezone' => $tz])->assertSessionHasNoErrors();

        expect(collect(evRecord($this, $submission)['events'])->first()['occurred_on'])->toBe($day);
    })->with(['Pacific/Honolulu', 'America/New_York', 'UTC', 'Europe/Berlin', 'Asia/Tokyo', 'Pacific/Kiritimati']);

    it('orders the history by the days entered, newest first, whatever order they were recorded in', function () {
        $submission = evSubmission(daysAgo: 20);

        foreach (['follow_up_sent' => 4, 'confirmation_received' => 15, 'interview' => 9] as $type => $days) {
            evPost($this, $submission, ['type' => $type, 'occurred_on' => Carbon::now('America/New_York')->subDays($days)->toDateString()]);
        }

        expect(collect(evRecord($this, $submission)['events'])->pluck('type')->all())->toBe(['follow_up_sent', 'interview', 'confirmation_received']);
    });

    it('labels every entry as the person\'s own, never a verified message', function () {
        $submission = evSubmission();
        evPost($this, $submission);

        expect(collect(evRecord($this, $submission)['events'])->pluck('source')->unique()->all())->toBe(['user']);
    });
});
