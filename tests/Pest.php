<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest tests (closure style) run on the application's TestCase with a fresh
| database per test. Older class-based PHPUnit tests keep their own setup.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature/FollowUps');

/*
|--------------------------------------------------------------------------
| Follow-up helpers
|--------------------------------------------------------------------------
*/

/**
 * Dallas HVAC with an admin, customer John Smith and an open estimate conversation.
 *
 * @return array{0: User, 1: Customer, 2: Conversation}
 */
function followUpBusiness(string $name = 'Dallas HVAC'): array
{
    $admin = User::factory()->admin()->create(['name' => 'Mohsin']);
    $admin->organization->update(['name' => $name]);
    $customer = Customer::factory()->for($admin->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
    $conversation = Conversation::factory()->for($customer)->create([
        'organization_id' => $admin->organization_id,
        'subject' => 'Your HVAC estimate',
        'last_message_at' => now(),
    ]);

    return [$admin, $customer, $conversation];
}

/**
 * Automatic follow-up emails on, approval off, verified sender sales@example.com.
 */
function allowAutomaticFollowUpEmail(Organization $organization): void
{
    $organization->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();

    if (! $organization->emailConnections()->exists()) {
        EmailConnection::factory()->verified()->default()->create([
            'organization_id' => $organization->id,
            'domain' => 'example.com',
            'sender_email' => 'sales@example.com',
            'sender_name' => $organization->name,
        ]);
    }
}

/**
 * An active automation and a pending automated follow-up from it.
 */
function automatedFollowUp(Conversation $conversation, array $attributes = []): FollowUp
{
    $automation = Automation::factory()->active()->create([
        'organization_id' => $conversation->organization_id,
        'name' => 'Interested Customer Follow-Up',
        'trigger_type' => 'customer_reply_classified',
    ]);

    [$followUp] = app(FollowUpService::class)->scheduleAutomated(
        $automation,
        $conversation,
        now()->addDays(2),
        'Following up on your estimate',
        "Hi {{customer.first_name}},\n\nJust checking in to see if you had any questions about your estimate.\n\nBest,\n{{business.name}}",
        null,
    );

    if ($attributes !== []) {
        $followUp->forceFill($attributes)->save();
    }

    return $followUp;
}

/**
 * Store a reply from the conversation's customer.
 */
function customerReply(Conversation $conversation, string $text = 'Yes, please send me the details.'): Message
{
    $message = new Message;
    $message->forceFill([
        'organization_id' => $conversation->organization_id,
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Inbound,
        'channel' => 'email',
        'provider' => 'postmark',
        'from_address' => $conversation->customer->email,
        'to_address' => 'reply+'.str_repeat('c', 40).'@inbound.quoteflow.ai',
        'subject' => 'Re: '.$conversation->subject,
        'body_text' => $text,
        'provider_message_id' => fake()->uuid(),
        'status' => MessageStatus::Received,
        'received_at' => now(),
    ])->save();

    return $message;
}

/**
 * Let the follow-up become due: travel past its due time and run the scheduler step.
 */
function makeDue(FollowUp $followUp): void
{
    test()->travelTo($followUp->refresh()->due_at->addMinute());
    app(FollowUpProcessor::class)->markDue();
}
