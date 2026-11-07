<?php

use App\Enums\CustomerStatus;
use App\Enums\FollowUpStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailJob;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\WhatsAppSettings;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\Settings\BusinessSettingsService;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

const WA_PHONE_ID = '106540352242922';
const WA_SECRET = 'app-secret-0123456789abcdef';

beforeEach(function () {
    Queue::fake([SendEmailJob::class]);
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness(); // John Smith, john@example.com
    $this->organization = $this->admin->organization;
    allowAutomaticFollowUpEmail($this->organization);

    app(BusinessSettingsService::class)->updateFollowUpChannels($this->admin, ['channels' => 'both', 'primary' => 'whatsapp']);
    $this->connection = new WhatsAppConnection;
    $this->connection->forceFill([
        'organization_id' => $this->organization->id, 'phone_number_id' => WA_PHONE_ID, 'business_account_id' => '102290129340398',
        'display_phone_number' => '+1 214-555-0100', 'verified_name' => 'Dallas HVAC', 'access_token' => 'EAAG-test-token-1234567890',
        'app_secret' => WA_SECRET, 'webhook_verify_token' => 'verify-me', 'status' => WhatsAppConnection::STATUS_ACTIVE, 'verified_at' => now(),
    ])->save();

    $this->customer->forceFill(['phone' => '(214) 555-0142', 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'staff'])->save();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
});

/** Post a signed webhook, as Meta does. */
function waWebhook(array $value): TestResponse
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => WA_PHONE_ID]] + $value]]]]]);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, WA_SECRET)], $payload);
}

function waText(string $from, string $text, string $id = 'wamid.IN1', string $name = 'John'): array
{
    return [
        'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
        'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
    ];
}

// ─── Receiving ──────────────────────────────────────────────────────────────────────────

test('a WhatsApp message from a customer joins their conversation and counts as a reply', function () {
    $followUp = automatedFollowUp($this->conversation);
    $this->travel(1)->minute();

    waWebhook(waText('12145550142', 'Yes please, when can you come?'))->assertOk();

    $message = Message::where('direction', 'inbound')->sole();
    expect($message->channel)->toBe(MessageChannel::WhatsApp)
        ->and($message->conversation_id)->toBe($this->conversation->id)
        ->and($message->body_text)->toBe('Yes please, when can you come?')
        ->and($message->from_address)->toBe('+12145550142')
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Skipped); // a reply stops follow-ups, as with email
});

test('a message from a new number becomes a new lead who agreed to WhatsApp', function () {
    waWebhook(waText('923001234567', 'Hi, I need a 5 marla plot in DHA Phase 5', 'wamid.NEW', 'Ali Raza'))->assertOk();

    $lead = Customer::where('phone_e164', '+923001234567')->sole();
    expect($lead->name)->toBe('Ali Raza')
        ->and($lead->email)->toBeNull()
        ->and($lead->preferred_channel)->toBe(MessageChannel::WhatsApp)
        ->and($lead->whatsapp_opt_in_source)->toBe('inbound')
        ->and(Conversation::where('customer_id', $lead->id)->sole()->subject)->toBe('WhatsApp chat');
});

test('a retried delivery is stored once', function () {
    waWebhook(waText('12145550142', 'Hello'));
    waWebhook(waText('12145550142', 'Hello'));

    expect(Message::where('direction', 'inbound')->count())->toBe(1);
});

test('a reply makes an inactive customer active again', function () {
    $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();

    waWebhook(waText('12145550142', 'Still interested!'));

    expect($this->customer->fresh()->status)->toBe(CustomerStatus::Active);
});

test('events for another business\'s number are ignored', function () {
    $payload = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '999999999'], 'messages' => [['from' => '12145550142', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'hi']]]]]]]]]);

    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, WA_SECRET)], $payload)
        ->assertUnauthorized();

    expect(Message::count())->toBe(0);
});

test('delivery updates mark the business\'s messages delivered or failed', function () {
    waWebhook(waText('12145550142', 'Hi'));
    $sent = app(WhatsAppMessenger::class)->sendText($this->conversation, 'Thanks John!');

    waWebhook(['statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered', 'recipient_id' => '12145550142']]]);
    expect($sent->fresh()->status)->toBe(MessageStatus::Delivered);

    waWebhook(['statuses' => [['id' => 'wamid.OUT1', 'status' => 'failed', 'errors' => [['title' => 'Re-engagement message', 'error_data' => ['details' => 'More than 24 hours have passed.']]]]]]);
    expect($sent->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('More than 24 hours have passed.');
});

// ─── Replying ───────────────────────────────────────────────────────────────────────────

test('within 24 hours of the customer\'s message the business can reply on WhatsApp from the conversation', function () {
    waWebhook(waText('12145550142', 'Can you do Thursday?'));

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->assertSet('replyChannel', 'whatsapp')
        ->set('replyBody', 'Thursday at 10 works.')->call('sendReply')
        ->assertSee('WhatsApp message sent to +12145550142.');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), WA_PHONE_ID.'/messages') && $request['type'] === 'text' && $request['text']['body'] === 'Thursday at 10 works.' && $request['to'] === '12145550142');
    expect(Message::where('direction', 'outbound')->sole())->channel->toBe(MessageChannel::WhatsApp)->status->toBe(MessageStatus::Sent);
});

test('after 24 hours WhatsApp replies are refused with the reason, and email stays available', function () {
    waWebhook(waText('12145550142', 'Can you do Thursday?'));
    $this->travel(25)->hours();

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->assertSet('replyChannel', 'email')
        ->call('$set', 'composerOpen', true)
        ->assertSee('WhatsApp replies are possible for 24 hours after the customer');
});

// ─── Follow-ups ─────────────────────────────────────────────────────────────────────────

test('a follow-up goes on WhatsApp as written while the 24-hour window is open', function () {
    waWebhook(waText('12145550142', 'I will think about it'));
    $followUp = automatedFollowUp($this->conversation, ['due_at' => now()->addMinutes(5)]);
    $this->travel(2)->hours(); // the reply came before the follow-up was planned, so it doesn't stop it

    makeDue($followUp);

    expect($followUp->fresh())->status->toBe(FollowUpStatus::Completed)->outcome->toBe('Follow-up sent on WhatsApp automatically.');
    Http::assertSent(fn (Request $request) => ($request['type'] ?? null) === 'text' && str_starts_with($request['text']['body'], 'Hi John,'));
});

test('outside the window a follow-up uses the approved template with the customer\'s first name', function () {
    $this->connection->forceFill(['follow_up_template' => 'quote_follow_up', 'follow_up_template_language' => 'en_US'])->save();
    $followUp = automatedFollowUp($this->conversation);

    makeDue($followUp);

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Completed);
    Http::assertSent(fn (Request $request) => ($request['type'] ?? null) === 'template'
        && $request['template']['name'] === 'quote_follow_up' && $request['template']['language']['code'] === 'en_US'
        && $request['template']['components'][0]['parameters'][0]['text'] === 'John');
});

test('outside the window without a template, the follow-up goes by email instead', function () {
    $followUp = automatedFollowUp($this->conversation);

    makeDue($followUp);

    expect(Message::where('direction', 'outbound')->sole()->channel)->toBe(MessageChannel::Email);
    Http::assertNothingSent();
});

test('customers without consent are followed up by email', function () {
    $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save();
    $this->connection->forceFill(['follow_up_template' => 'quote_follow_up'])->save();

    makeDue(automatedFollowUp($this->conversation));

    expect(Message::where('direction', 'outbound')->sole()->channel)->toBe(MessageChannel::Email);
    Http::assertNothingSent();
});

// ─── Template setting ───────────────────────────────────────────────────────────────────

test('the follow-up template name and language are checked and saved', function () {
    Livewire::actingAs($this->admin->fresh())->test(WhatsAppSettings::class)
        ->set('followUpTemplate', 'Quote Follow Up')->call('saveTemplate')->assertHasErrors('followUpTemplate')
        ->set('followUpTemplate', 'quote_follow_up')->set('templateLanguage', 'en_US')->call('saveTemplate')
        ->assertHasNoErrors()->assertSee('Follow-up template saved.');

    expect($this->connection->fresh())->follow_up_template->toBe('quote_follow_up')->follow_up_template_language->toBe('en_US');
});
