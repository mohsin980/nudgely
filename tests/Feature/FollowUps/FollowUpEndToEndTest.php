<?php

use App\Enums\CustomerReplyIntent;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Jobs\ProcessFollowUpJob;
use App\Jobs\SendEmailJob;
use App\Models\FollowUp;
use App\Models\Message;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use App\Services\Email\EmailService;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\Fakes\FakeReplyClassifier;

uses(PostmarkInboundPayloads::class);

/**
 * Webhook → AI classification (faked provider) → automation → FollowUp → scheduler → job → EmailService.
 */
beforeEach(function () {
    Queue::fake([SendEmailJob::class]);
    config(['ai.classification.enabled' => true]);
    $this->configureInboundWebhook();
    $classifier = $this->classifier = new FakeReplyClassifier;
    app(ReplyClassifierManager::class)->extend('openai', fn () => $classifier);

    // Business: Dallas HVAC, customer John Smith, verified sales@example.com, unattended email allowed.
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness('Dallas HVAC');
    allowAutomaticFollowUpEmail($this->admin->organization);

    // Automation: Interested Customer Follow-Up → schedule follow-up in 2 days.
    $automation = app(AutomationTemplates::class)->install($this->admin->organization, $this->admin, 'interested');
    $automation->update(['name' => 'Interested Customer Follow-Up']);
    app(AutomationBuilder::class)->activate($automation, $this->admin);

    // Conversation: estimate sent.
    $this->estimate = app(EmailService::class)->sendToConversation($this->conversation, 'Your HVAC estimate', null, 'Here is your estimate for the AC replacement.');

    // The customer replies through the inbound webhook, to the estimate's secure Reply-To address.
    $this->customerEmails = function (string $text, CustomerReplyIntent $intent, float $confidence): void {
        $this->classifier->willReturn($intent, $confidence);
        $payload = $this->postmarkInbound($this->estimate->reply_to, ['MessageID' => fake()->uuid(), 'TextBody' => $text, 'StrippedTextReply' => $text, 'HtmlBody' => '']);

        $this->postJson('/webhooks/email/inbound/postmark', $payload, $this->webhookAuth())->assertOk();
    };
});

function followUpEmails(): Collection
{
    return Message::where('direction', 'outbound')->where('metadata->type', 'follow_up')->get();
}

test('interested customer gets one automatic follow-up email after 2 days', function () {
    ($this->customerEmails)("I'll think about it.", CustomerReplyIntent::Interested, 0.92);

    $followUp = FollowUp::sole();
    expect($followUp)
        ->type->toBe(FollowUpType::Automated)
        ->status->toBe(FollowUpStatus::Pending)
        ->automation_id->not->toBeNull()
        ->and(now()->diffInDays($followUp->due_at))->toEqualWithDelta(2, 0.01)
        ->and($followUp->reason())->toBe('Interested Customer Follow-Up');

    // After 2 days the scheduler marks it due and the job checks: no reply, not opted out,
    // conversation open, automation active, automatic email on, approval off → send.
    $this->travel(2)->days();
    $this->travel(1)->minute();
    $this->artisan('follow-ups:process-due')->assertSuccessful();

    $email = followUpEmails()->sole();
    expect($followUp->fresh())
        ->status->toBe(FollowUpStatus::Completed)
        ->completed_at->not->toBeNull()
        ->message_id->toBe($email->id)
        ->outcome->toBe('Follow-up email sent automatically.')
        ->and($email->from_address)->toBe('sales@example.com')
        ->and($email->to_address)->toBe('john@example.com')
        ->and($email->conversation_id)->toBe($this->conversation->id)
        ->and($email->body_text)->toContain('Hi John,')->toContain('Dallas HVAC');
    Queue::assertPushed(SendEmailJob::class, fn ($job) => $job->messageId === $email->id);

    // Run the job again: no duplicate email.
    (new ProcessFollowUpJob($followUp->id))->handle(app(FollowUpProcessor::class));
    $this->artisan('follow-ups:process-due');

    expect(followUpEmails())->toHaveCount(1)
        ->and(app(FollowUpProcessor::class)->process($followUp->id))->toBe(FollowUpProcessor::ALREADY_PROCESSED);
});

test('a reply before the due date skips the follow-up and sends nothing', function () {
    ($this->customerEmails)("I'll think about it.", CustomerReplyIntent::Interested, 0.92);
    $followUp = FollowUp::sole();

    $this->travel(1)->day();
    ($this->customerEmails)('Yes, please send me the details.', CustomerReplyIntent::NeedsMoreInformation, 0.9);

    expect($followUp->fresh())
        ->status->toBe(FollowUpStatus::Skipped)
        ->skip_reason->toBe(FollowUpSkipReason::CustomerReplied)
        ->and($followUp->fresh()->skip_reason->value)->toBe('customer_replied');

    $this->travel(2)->days();
    $this->artisan('follow-ups:process-due');

    expect(followUpEmails())->toHaveCount(0)
        ->and(FollowUp::sole()->status)->toBe(FollowUpStatus::Skipped);
});
