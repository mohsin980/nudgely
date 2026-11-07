<?php

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerReplyIntent;
use App\Enums\FollowUpStatus;
use App\Enums\MessageChannel;
use App\Jobs\SendEmailJob;
use App\Models\Automation;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\Automation\AutomationActionManager;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationTemplates;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendEmailJob::class]);
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness(); // John Smith, john@example.com
    $this->organization = $this->admin->organization;
    allowAutomaticFollowUpEmail($this->organization);
    $this->organization->forceFill(['automations_enabled' => true])->save();

    app(BusinessSettingsService::class)->updateFollowUpChannels($this->admin, ['channels' => 'both', 'primary' => 'email']);
    $connection = new WhatsAppConnection;
    $connection->forceFill([
        'organization_id' => $this->organization->id, 'phone_number_id' => '106540352242000', 'business_account_id' => '102290129340398',
        'display_phone_number' => '+1 214-555-0100', 'verified_name' => 'Dallas HVAC', 'access_token' => 'EAAG-test-token-1234567890',
        'app_secret' => 'secret-channels-0123456789', 'webhook_verify_token' => 'verify-me', 'status' => WhatsAppConnection::STATUS_ACTIVE, 'verified_at' => now(),
    ])->save();
    $this->connection = $connection;

    $this->customer->forceFill(['phone' => '(214) 555-0142', 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'staff'])->save();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]])]);
});

/** The customer wrote on WhatsApp recently, so free text is allowed for 24 hours. */
function openWhatsAppWindow($test): void
{
    (new Message)->forceFill(['organization_id' => $test->organization->id, 'conversation_id' => $test->conversation->id, 'direction' => 'inbound', 'channel' => 'whatsapp',
        'from_address' => '+12145550142', 'to_address' => '+12145550100', 'subject' => 'x', 'body_text' => 'Hi', 'status' => 'received', 'received_at' => now()->subHour(),
        'provider_message_id' => 'wamid.IN'.uniqid()])->save();
}

function whatsAppTexts(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $request) => ($request['type'] ?? null) === 'text')->map(fn (Request $request) => $request['text']['body'])->values()->all();
}

function sendMessageAction($test, string $channel): AutomationActionResult
{
    $automation = Automation::factory()->active()->create(['organization_id' => $test->organization->id]);
    $action = $automation->actions()->create(['type' => AutomationActionType::SendEmail, 'requires_approval' => false,
        'configuration' => ['channel' => $channel, 'subject' => 'Your estimate', 'body' => 'Hi {{customer.first_name}}, any questions about your estimate?']]);

    return app(AutomationActionManager::class)->execute($action, new AutomationContext(
        organizationId: $test->organization->id, triggerType: AutomationTriggerType::CustomerReplyClassified, eventId: 'classification:'.uniqid(),
        customerId: $test->customer->id, conversationId: $test->conversation->id, intent: CustomerReplyIntent::Interested, confidence: 0.9,
    ));
}

// ─── "Send message" action ──────────────────────────────────────────────────────────────

test('an automation can message the customer on WhatsApp', function () {
    openWhatsAppWindow($this);

    $result = sendMessageAction($this, 'whatsapp');

    expect($result->status)->toBe(AutomationActionRunStatus::Completed)->and($result->message)->toBe('WhatsApp message sent to the customer.')
        ->and(whatsAppTexts())->toBe(['Hi John, any questions about your estimate?'])
        ->and(Message::where('direction', 'outbound')->where('channel', MessageChannel::Email)->count())->toBe(0);
});

test('"Email and WhatsApp" sends both', function () {
    openWhatsAppWindow($this);

    $result = sendMessageAction($this, 'both');

    expect($result->status)->toBe(AutomationActionRunStatus::Completed)->and($result->message)->toBe('Sent to the customer by email and WhatsApp.')
        ->and(whatsAppTexts())->toHaveCount(1)
        ->and(Message::where('direction', 'outbound')->where('channel', MessageChannel::Email)->count())->toBe(1);
});

test('outside the 24-hour window WhatsApp uses the approved template, and without one it explains why', function () {
    expect(sendMessageAction($this, 'whatsapp'))->status->toBe(AutomationActionRunStatus::Failed)
        ->message->toContain('WhatsApp needs an approved template');

    $this->connection->forceFill(['follow_up_template' => 'quote_follow_up'])->save();
    expect(sendMessageAction($this, 'whatsapp')->status)->toBe(AutomationActionRunStatus::Completed);
    Http::assertSent(fn (Request $request) => ($request['type'] ?? null) === 'template' && $request['template']['components'][0]['parameters'][0]['text'] === 'John');
});

test('"The customer\'s channel" follows the customer\'s preference, and older automations keep sending email', function () {
    openWhatsAppWindow($this);
    $this->customer->forceFill(['preferred_channel' => MessageChannel::WhatsApp])->save();

    sendMessageAction($this, 'customer');
    expect(whatsAppTexts())->toHaveCount(1);

    $legacy = Automation::factory()->active()->create(['organization_id' => $this->organization->id]);
    $action = $legacy->actions()->create(['type' => AutomationActionType::SendEmail, 'requires_approval' => false, 'configuration' => ['subject' => 'Hi', 'body' => 'Hello']]);
    app(AutomationActionManager::class)->execute($action, new AutomationContext(organizationId: $this->organization->id, triggerType: AutomationTriggerType::CustomerReplyClassified,
        eventId: 'classification:legacy', customerId: $this->customer->id, conversationId: $this->conversation->id, intent: CustomerReplyIntent::Interested, confidence: 0.9));

    expect(whatsAppTexts())->toHaveCount(1)->and(Message::where('channel', MessageChannel::Email)->where('direction', 'outbound')->count())->toBe(1);
});

test('the builder accepts the channel choices and rejects others', function () {
    $save = fn (string $channel) => app(AutomationBuilder::class)->save($this->organization, $this->admin, [
        'name' => 'Check in', 'trigger_type' => 'customer_reply_classified', 'conditions' => [],
        'actions' => [['type' => 'send_email', 'requires_approval' => false, 'configuration' => ['channel' => $channel, 'subject' => 'Hi', 'body' => 'Hello']]],
    ]);

    expect($save('both')->actions()->sole()->configuration['channel'])->toBe('both')
        ->and(fn () => $save('sms'))->toThrow(Exception::class);
});

// ─── Follow-ups ─────────────────────────────────────────────────────────────────────────

test('a follow-up set to "Email and WhatsApp" is sent on both', function () {
    openWhatsAppWindow($this);
    $followUp = automatedFollowUp($this->conversation, ['channel' => 'both', 'due_at' => now()->addMinutes(5)]);
    $this->travel(2)->hours();

    makeDue($followUp);

    expect($followUp->fresh())->status->toBe(FollowUpStatus::Completed)->outcome->toBe('Follow-up sent by email and WhatsApp automatically.')
        ->and(whatsAppTexts())->toHaveCount(1)
        ->and(Message::where('channel', MessageChannel::Email)->where('direction', 'outbound')->count())->toBe(1);
});

test('a follow-up set to WhatsApp never falls back to email', function () {
    $followUp = automatedFollowUp($this->conversation, ['channel' => 'whatsapp']); // window closed, no template

    makeDue($followUp);

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Failed)
        ->and(Message::where('direction', 'outbound')->count())->toBe(0);
});

test('the new lead sequence reaches WhatsApp-only leads on their channel', function () {
    $automation = app(AutomationTemplates::class)->install($this->organization, $this->admin, 'new_lead_sequence');

    expect($automation->conditions()->pluck('type')->map(fn ($type) => $type->value ?? $type)->all())->not->toContain('customer_has_email')
        ->and($automation->actions()->get()->pluck('configuration.channel')->unique()->all())->toBe(['customer']);

    $followUp = automatedFollowUp($this->conversation, ['channel' => null]);
    expect($followUp->channel)->toBeNull();
    expect(FollowUp::query()->whereKey($followUp->id)->value('channel'))->toBeNull();
});
