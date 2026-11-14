<?php

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\AppointmentRequest;
use App\Models\AssistantChat;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Assistant\HandoverBrief;

/** A WhatsApp message in the conversation, as the business's own messenger stores it. */
function whatsAppMessage(Conversation $conversation, string $text, ?array $metadata = null): Message
{
    $message = new Message;
    $message->forceFill([
        'organization_id' => $conversation->organization_id,
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'channel' => MessageChannel::WhatsApp,
        'provider' => null,
        'from_address' => '+92 42 1234567',
        'to_address' => '+923001234567',
        'subject' => '',
        'body_text' => $text,
        'status' => MessageStatus::Sent,
        'metadata' => $metadata,
    ])->save();

    return $message;
}

/** A handed-over assistant chat, with the assistant's last reply stored. */
function handedOverChat(array $attributes = []): AssistantChat
{
    [$admin, $customer, $conversation] = followUpBusiness('Lahore Estates');

    $chat = new AssistantChat;
    $chat->forceFill(array_merge([
        'organization_id' => $admin->organization_id,
        'conversation_id' => $conversation->id,
        'customer_id' => $customer->id,
        'status' => AssistantChat::HANDED_OVER,
        'replies' => 1,
        'handover_reason' => 'The customer asked for a viewing.',
    ], $attributes))->save();

    whatsAppMessage($conversation, 'Viewings are on Saturday. Shall I hold 11 AM?', ['type' => 'assistant', 'assistant_chat_id' => (string) $chat->id]);

    return $chat->fresh();
}

test('the brief shows the last thing the assistant said', function () {
    $chat = handedOverChat();

    expect(app(HandoverBrief::class)->for($chat)['told'])->toBe('Viewings are on Saturday. Shall I hold 11 AM?');
});

test('a person\'s own message is not treated as the assistant\'s reply', function () {
    $chat = handedOverChat();
    whatsAppMessage($chat->conversation, 'Hi, this is Mohsin. I can help.');

    expect(app(HandoverBrief::class)->for($chat)['told'])->toBe('Viewings are on Saturday. Shall I hold 11 AM?');
});

test('open items list the unconfirmed time, missing contact details and no qualification', function () {
    $chat = handedOverChat();
    $chat->customer->forceFill(['email' => null, 'address' => null])->save();

    $appointment = new AppointmentRequest;
    $appointment->forceFill([
        'organization_id' => $chat->organization_id,
        'customer_id' => $chat->customer_id,
        'conversation_id' => $chat->conversation_id,
        'assistant_chat_id' => $chat->id,
        'starts_at' => now()->addDays(2)->setTime(11, 0)->utc(),
        'status' => AppointmentRequest::STATUS_REQUESTED,
    ])->save();

    $open = app(HandoverBrief::class)->for($chat)['open'];

    expect($open)->toHaveCount(3)
        ->and($open[0])->toStartWith('Confirm the requested time: ')
        ->and($open)->toContain('Not yet qualified', 'No email or address on file');
});

test('a qualified lead with contact details and no pending time has nothing open', function () {
    $chat = handedOverChat(['qualified_at' => now()]);

    expect(app(HandoverBrief::class)->for($chat)['open'])->toBe([]);
});

test('the handed-over banner shows the last reply and the open items', function () {
    $chat = handedOverChat();
    $owner = $chat->conversation->organization->users()->first();

    $this->actingAs($owner)->get(route('inbox.show', $chat->conversation_id))
        ->assertOk()
        ->assertSee('Viewings are on Saturday. Shall I hold 11 AM?')
        ->assertSee('Still open');
});
