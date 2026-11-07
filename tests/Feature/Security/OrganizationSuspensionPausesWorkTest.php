<?php

use App\Enums\FollowUpStatus;
use App\Events\CustomerCreated;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Automation\AutomationEngine;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Support\Facades\Queue;

/** Suspending a business pauses its background work; reactivating it lets the work carry on. */
function suspend(Organization $organization): void
{
    $organization->forceFill(['suspended_at' => now()])->save();
}

test('due follow-ups of a suspended business wait, and carry on after reactivation', function () {
    Queue::fake();
    [$admin, , $conversation] = followUpBusiness();
    allowAutomaticFollowUpEmail($admin->organization);
    $followUp = automatedFollowUp($conversation);
    suspend($admin->organization);

    $this->travel(3)->days();
    app(FollowUpProcessor::class)->markDue();

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Pending);
    Queue::assertNothingPushed();

    $admin->organization->forceFill(['suspended_at' => null])->save();
    app(FollowUpProcessor::class)->markDue();

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Due);
});

test('a follow-up already marked due is put back when the business is suspended before it runs', function () {
    [$admin, , $conversation] = followUpBusiness();
    allowAutomaticFollowUpEmail($admin->organization);
    $followUp = automatedFollowUp($conversation, ['status' => FollowUpStatus::Due, 'due_at' => now()->subMinute()]);
    suspend($admin->organization);

    expect(app(FollowUpProcessor::class)->process($followUp->id))->toBe(FollowUpProcessor::PAUSED)
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Pending)
        ->and(Message::query()->where('direction', 'outbound')->exists())->toBeFalse();
});

test('a suspended business cannot send email, even from a job queued earlier', function () {
    [$admin] = followUpBusiness();
    allowAutomaticFollowUpEmail($admin->organization);
    suspend($admin->organization);

    expect(fn () => app(EmailService::class)->assertCanSendFrom($admin->organization->emailConnections()->first(), $admin->organization_id))
        ->toThrow(EmailSendingNotAllowedException::class, 'suspended');
});

test('automation events of a suspended business start nothing', function () {
    [$admin, $customer] = followUpBusiness();
    $admin->organization->forceFill(['automations_enabled' => true])->save();
    suspend($admin->organization);

    expect(app(AutomationEngine::class)->evaluate(new CustomerCreated($admin->organization_id, $customer->id)))->toBe([]);
});

test('a suspended business\'s estimate links stop working until it is reactivated', function () {
    fakeEmailProvider();
    [$owner, $customer] = estimateBusiness();
    $url = sentEstimate($owner, $customer)->publicUrl();

    $this->get($url)->assertOk();

    suspend($owner->organization);
    $this->get($url)->assertNotFound();

    $owner->organization->forceFill(['suspended_at' => null])->save();
    $this->get($url)->assertOk();
});
