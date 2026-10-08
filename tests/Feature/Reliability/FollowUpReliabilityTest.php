<?php

use App\Enums\FollowUpStatus;
use App\Jobs\ProcessFollowUpJob;
use App\Jobs\SendEmailJob;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    allowAutomaticFollowUpEmail($this->admin->organization);
    $this->provider = fakeEmailProvider();
    $this->processor = app(FollowUpProcessor::class);
    // Jobs are run by hand below, so each step of a retry can be shown.
    Queue::fake();
});

function followUpEmailCount(): int
{
    return Message::query()->where('direction', 'outbound')->where('metadata->type', 'follow_up')->count();
}

test('a retried follow-up job sends one email and creates no second follow-up', function () {
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp);

    expect($this->processor->process($followUp->id))->toBe(FollowUpProcessor::SENT)
        ->and($this->processor->process($followUp->id))->toBe(FollowUpProcessor::ALREADY_PROCESSED)
        ->and($this->processor->process($followUp->id))->toBe(FollowUpProcessor::ALREADY_PROCESSED);

    expect(followUpEmailCount())->toBe(1)
        ->and(FollowUp::count())->toBe(1);

    // The send job itself is delivered twice by the queue: still one email to the customer.
    $messageId = Message::query()->where('metadata->type', 'follow_up')->value('id');
    (new SendEmailJob($messageId))->handle(app(EmailService::class));
    (new SendEmailJob($messageId))->handle(app(EmailService::class));

    expect($this->provider->sent)->toHaveCount(1);
});

test('a follow-up that was cancelled after it was queued never sends', function () {
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp);
    $followUp->forceFill(['status' => FollowUpStatus::Cancelled])->save();

    expect($this->processor->process($followUp->id))->toBe(FollowUpProcessor::ALREADY_PROCESSED)
        ->and($this->provider->sent)->toHaveCount(0);
});

test('a follow-up whose customer was deleted sends nothing', function () {
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp);
    Customer::whereKey($this->customer->id)->first()->forceFill(['email' => 'not-an-address'])->save();

    $outcome = $this->processor->process($followUp->id);

    expect($outcome)->not->toBe(FollowUpProcessor::SENT)
        ->and($this->provider->sent)->toHaveCount(0);
});

test('deleting the estimate removes its follow-ups, so none is sent about a missing estimate', function () {
    $estimate = draftEstimate($this->admin, $this->customer);
    $followUp = automatedFollowUp($this->conversation, ['estimate_id' => $estimate->id]);
    makeDue($followUp);

    $estimate->delete();

    expect(FollowUp::whereKey($followUp->id)->exists())->toBeFalse()
        ->and($this->processor->process($followUp->id))->toBe(FollowUpProcessor::MISSING)
        ->and($this->provider->sent)->toHaveCount(0);
});

test('a job for a follow-up that no longer exists is skipped safely', function () {
    expect($this->processor->process(987654))->toBe(FollowUpProcessor::MISSING);

    (new ProcessFollowUpJob(987654))->handle($this->processor);

    expect($this->provider->sent)->toHaveCount(0);
});

test('a customer reply after the job was queued stops the follow-up', function () {
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp);

    customerReply($this->conversation);

    expect($this->processor->process($followUp->id))->toBe(FollowUpProcessor::SKIPPED)
        ->and($this->provider->sent)->toHaveCount(0);
});

test('a due follow-up whose job was lost is queued again, and fresh ones are not', function () {
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp); // marked due and queued once

    // The job never reached the queue: it is still due, untouched for a long time.
    DB::table('follow_ups')->where('id', $followUp->id)->update(['updated_at' => now()->subMinutes(30)]);

    expect($this->processor->markDue())->toBe(1);
    Queue::assertPushed(ProcessFollowUpJob::class, fn (ProcessFollowUpJob $job) => $job->followUpId === $followUp->id);

    // Queued just now: not queued again.
    expect($this->processor->markDue())->toBe(0);
});

test('a lost-then-recovered follow-up is still sent exactly once', function () {
    $followUp = automatedFollowUp($this->conversation);
    makeDue($followUp);
    DB::table('follow_ups')->where('id', $followUp->id)->update(['updated_at' => now()->subMinutes(30)]);
    $this->processor->markDue();

    // Two jobs for the same follow-up run: only one sends.
    $this->processor->process($followUp->id);
    $this->processor->process($followUp->id);

    expect(followUpEmailCount())->toBe(1);
});
