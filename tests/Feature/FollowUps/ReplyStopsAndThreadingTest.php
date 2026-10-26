<?php

use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\EmailReplyRoute;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\User;
use App\Services\Automation\AutomatedEmailPolicy;
use App\Services\Email\EmailService;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use App\Services\Email\ReplyRouteService;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'email.providers.postmark.server_token' => 'server-token',
        'email.inbound.reply_domain' => 'inbound.quoteflow.ai',
    ]);

    $this->owner = User::factory()->owner()->create();
    $this->organization = $this->owner->organization;
    $this->customer = Customer::factory()->create(['organization_id' => $this->organization->id, 'email' => 'jane@customer.test']);
    $this->conversation = Conversation::factory()->create(['organization_id' => $this->organization->id, 'customer_id' => $this->customer->id]);
    $automation = Automation::factory()->active()->create(['organization_id' => $this->organization->id]);
    $this->followUp = FollowUp::factory()->automated()->create(['conversation_id' => $this->conversation->id, 'automation_id' => $automation->id]);
    // Scheduled an hour ago, so replies arriving now come after it.
    $this->followUp->forceFill(['created_at' => now()->subHour()])->save();
});

/** A stored inbound reply on the conversation, optionally from another address on its reply route. */
function storedReply(Conversation $conversation, array $attributes = []): Message
{
    $route = EmailReplyRoute::query()->where('conversation_id', $conversation->id)->latest('id')->first();

    $message = new Message;
    $message->forceFill(array_merge([
        'organization_id' => $conversation->organization_id,
        'conversation_id' => $conversation->id,
        'email_reply_route_id' => $route?->id,
        'direction' => MessageDirection::Inbound,
        'channel' => 'email',
        'provider' => 'postmark',
        'from_address' => 'jane@customer.test',
        'to_address' => 'reply@inbound.quoteflow.ai',
        'subject' => 'Re: Your estimate',
        'body_text' => 'Sounds good.',
        'status' => MessageStatus::Received,
        'received_at' => now(),
    ], $attributes))->save();

    return $message;
}

test('a reply from another address on the reply route stops the follow-up', function () {
    app(ReplyRouteService::class)->createFor($this->conversation);
    storedReply($this->conversation, [
        'from_address' => 'spouse@other.test',
        'conversation_id' => null,
        'status' => MessageStatus::NeedsReview,
    ]);

    expect(app(FollowUpProcessor::class)->stopReason($this->followUp))->toBe(FollowUpSkipReason::CustomerReplied);
});

test('an automatic reply (out of office) does not stop the follow-up', function () {
    storedReply($this->conversation, ['metadata' => ['automated' => '1']]);

    expect(app(FollowUpProcessor::class)->stopReason($this->followUp))->toBeNull();
});

test('a real reply from the customer stops the follow-up', function () {
    storedReply($this->conversation);

    expect(app(FollowUpProcessor::class)->stopReason($this->followUp))->toBe(FollowUpSkipReason::CustomerReplied);
});

test('an expired estimate stops its automated follow-ups', function () {
    $estimate = Estimate::factory()->create(['customer_id' => $this->customer->id, 'organization_id' => $this->organization->id]);
    $estimate->forceFill(['status' => EstimateStatus::Expired, 'sent_at' => now()->subDays(20), 'expired_at' => now()])->save();
    $this->followUp->forceFill(['estimate_id' => $estimate->id])->save();

    expect(app(FollowUpProcessor::class)->stopReason($this->followUp))->toBe(FollowUpSkipReason::EstimateClosed);
});

test('expiring an estimate skips the follow-ups scheduled for it', function () {
    $estimate = Estimate::factory()->create(['customer_id' => $this->customer->id, 'organization_id' => $this->organization->id]);
    $estimate->forceFill(['status' => EstimateStatus::Sent, 'sent_at' => now()->subDays(40), 'valid_until' => now()->subDays(2)->toDateString()])->save();
    $this->followUp->forceFill(['estimate_id' => $estimate->id])->save();

    app(EstimateService::class)->expireDue();

    expect($this->followUp->refresh()->skip_reason)->toBe(FollowUpSkipReason::EstimateClosed);
});

test('a bounced or complained address is never followed up', function () {
    $this->customer->forceFill(['email_suppressed_address' => 'jane@customer.test', 'email_suppression_reason' => 'bounced'])->save();

    expect(app(FollowUpProcessor::class)->stopReason($this->followUp))->toBe(FollowUpSkipReason::EmailSuppressed);

    $blocked = app(AutomatedEmailPolicy::class)->checkDelivery($this->organization, $this->conversation, $this->customer->refresh(), null);
    expect($blocked->data['reason'] ?? null)->toBe('suppressed');
});

test('replies from the customer are threaded to their last real message', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['domain' => 'example.com', 'sender_email' => 'sales@example.com']);
    storedReply($this->conversation, ['header_message_id' => '<customer-1@mail.test>', 'references' => null]);
    // An out-of-office message after it is not what the business is answering.
    storedReply($this->conversation, ['header_message_id' => '<ooo-1@mail.test>', 'metadata' => ['automated' => '1'], 'received_at' => now()->addMinute()]);

    Http::fake(['api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-1', 'ErrorCode' => 0, 'Message' => 'OK'])]);
    $outbound = app(EmailService::class)->sendToConversation($this->conversation, 'Re: Your estimate', null, 'Thanks, here it is.');

    expect($outbound->in_reply_to)->toBe('<customer-1@mail.test>')
        ->and($outbound->references)->toBe('<customer-1@mail.test>');

    app(EmailService::class)->deliver($outbound);

    Http::assertSent(function ($request) {
        $headers = collect($request['Headers'] ?? [])->pluck('Value', 'Name');

        return $headers->get('In-Reply-To') === '<customer-1@mail.test>'
            && $headers->get('References') === '<customer-1@mail.test>';
    });
});

test('a later reply extends the references chain instead of replacing it', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['domain' => 'example.com', 'sender_email' => 'sales@example.com']);
    storedReply($this->conversation, ['header_message_id' => '<one@mail.test>', 'references' => '<zero@mail.test>']);

    $outbound = app(EmailService::class)->sendToConversation($this->conversation, 'Re', null, 'Hi');

    expect($outbound->references)->toBe('<zero@mail.test> <one@mail.test>');
});

test('the inbound webhook keeps the headers that mark automatic replies', function () {
    $payload = [
        'MessageID' => 'pm-in-1',
        'FromFull' => ['Email' => 'jane@customer.test', 'Name' => 'Jane'],
        'ToFull' => [['Email' => 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai']],
        'OriginalRecipient' => 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai',
        'Subject' => 'Out of office',
        'TextBody' => 'I am away.',
        'Headers' => [['Name' => 'Auto-Submitted', 'Value' => 'auto-replied']],
    ];

    $email = app(InboundEmailProviderManager::class)->driver('postmark')->parse($payload);

    expect($email->headers['auto-submitted'])->toBe('auto-replied');
});
