<?php

namespace App\Services\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\LeadStage;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\WhatsApp\WhatsAppSendException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\Leads\LeadStageService;
use Illuminate\Support\Facades\Log;

/**
 * Sends WhatsApp messages into a conversation and keeps them on its timeline, like emails.
 *
 * WhatsApp's rule: within 24 hours of the customer's last message the business may write freely;
 * outside it, only an approved template may be sent. The message is stored as "sending" before Meta
 * is called (so a unique automation key stops a second worker sending it twice), then marked sent or
 * failed. Inactive customers and customers without consent are never messaged.
 */
class WhatsAppMessenger
{
    public const WINDOW_HOURS = 24;

    public function __construct(private readonly WhatsAppClient $client) {}

    /**
     * Whether the customer wrote to the business on WhatsApp in the last 24 hours (free text allowed).
     */
    public function windowOpen(Customer $customer): bool
    {
        return Message::query()
            ->where('organization_id', $customer->organization_id)
            ->where('direction', MessageDirection::Inbound)
            ->where('channel', MessageChannel::WhatsApp)
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->id))
            ->where('received_at', '>', now()->subHours(self::WINDOW_HOURS))
            ->exists();
    }

    /**
     * Why WhatsApp can't be used for this customer right now, or null if it can.
     */
    public function unavailableReason(Customer $customer): ?string
    {
        $connection = $this->connection($customer->organization_id);

        return match (true) {
            $connection === null || ! $connection->isActive() => 'WhatsApp is not connected.',
            ! $customer->isActive() => 'This customer is marked inactive.',
            $customer->whatsapp_opt_in_at === null => 'This customer hasn\'t agreed to WhatsApp messages.',
            $customer->phone_e164 === null => 'This customer has no phone number WhatsApp can use.',
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $metadata
     *
     * @throws WhatsAppSendException
     */
    public function sendText(Conversation $conversation, string $body, array $metadata = []): Message
    {
        $customer = $this->customerFor($conversation);

        if (! $this->windowOpen($customer)) {
            throw new WhatsAppSendException('More than 24 hours have passed since the customer\'s last WhatsApp message, so WhatsApp only allows an approved template. Send an email instead, or wait for them to write.');
        }

        return $this->deliver($conversation, $customer, $body, $metadata,
            fn (WhatsAppConnection $connection) => $this->client->sendText($connection, $customer->phone_e164, $body));
    }

    /**
     * An approved template. $preview is what the timeline shows (the template's text as the business wrote it).
     *
     * @param  list<string>  $parameters
     * @param  array<string, string>  $metadata
     *
     * @throws WhatsAppSendException
     */
    public function sendTemplate(Conversation $conversation, string $name, string $language, array $parameters, string $preview, array $metadata = []): Message
    {
        $customer = $this->customerFor($conversation);

        return $this->deliver($conversation, $customer, $preview, $metadata + ['whatsapp_template' => $name],
            fn (WhatsAppConnection $connection) => $this->client->sendTemplate($connection, $customer->phone_e164, $name, $language, $parameters));
    }

    /**
     * An automatic message (follow-up or automation): as written within 24 hours of the customer's last WhatsApp
     * message, otherwise as the business's approved follow-up template with the customer's first name.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws WhatsAppSendException when neither is possible, with the reason
     */
    public function sendAutomated(Conversation $conversation, string $text, array $metadata = []): Message
    {
        $customer = $this->customerFor($conversation);

        if ($this->windowOpen($customer)) {
            return $this->sendText($conversation, $text, $metadata);
        }

        $connection = $this->connection($customer->organization_id);

        if (blank($connection?->follow_up_template)) {
            throw new WhatsAppSendException('Not sent on WhatsApp: it has been more than 24 hours since the customer\'s last WhatsApp message, so WhatsApp needs an approved template. Add one in Settings → WhatsApp.');
        }

        return $this->sendTemplate($conversation, $connection->follow_up_template, $connection->follow_up_template_language ?: 'en',
            [(string) ($customer->first_name ?: $customer->name)], 'WhatsApp template “'.$connection->follow_up_template.'”', $metadata);
    }

    /**
     * Whether an automatic message can go on WhatsApp right now (connected, consent, number, and either the 24-hour
     * window is open or an approved template is set).
     */
    public function canSendAutomated(Customer $customer): bool
    {
        return $this->unavailableReason($customer) === null
            && ($this->windowOpen($customer) || filled($this->connection($customer->organization_id)?->follow_up_template));
    }

    public function connection(int $organizationId): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->where('organization_id', $organizationId)->first();
    }

    /**
     * @param  array<string, string>  $metadata
     * @param  \Closure(WhatsAppConnection): string  $send  returns Meta's message id
     *
     * @throws WhatsAppSendException
     */
    private function deliver(Conversation $conversation, Customer $customer, string $body, array $metadata, \Closure $send): Message
    {
        if (($reason = $this->unavailableReason($customer)) !== null) {
            throw new WhatsAppSendException('Not sent on WhatsApp: '.$reason);
        }

        $connection = $this->connection($customer->organization_id);

        $message = new Message;
        $message->forceFill([
            'organization_id' => $conversation->organization_id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::WhatsApp,
            'provider' => null,
            'from_address' => $connection->display_phone_number ?: $connection->phone_number_id,
            'from_name' => $connection->verified_name,
            'to_address' => $customer->phone_e164,
            'to_name' => $customer->name,
            'subject' => mb_substr((string) $conversation->subject, 0, 998),
            'body_text' => $body,
            'metadata' => $metadata ?: null,
            'status' => MessageStatus::Sending,
        ])->save();

        try {
            $wamid = $send($connection);
        } catch (WhatsAppSendException $e) {
            $message->forceFill(['status' => MessageStatus::Failed, 'failed_at' => now(), 'failure_reason' => mb_substr($e->getMessage(), 0, 255)])->save();
            Log::warning('WhatsApp message not sent.', ['organization_id' => $message->organization_id, 'message_id' => $message->id]);

            throw $e;
        }

        $message->forceFill(['status' => MessageStatus::Sent, 'provider_message_id' => $wamid, 'sent_at' => now()])->save();
        $conversation->recordActivity(now(), ConversationStatus::WaitingCustomer);
        app(LeadStageService::class)->advance($conversation->organization_id, $customer->id, LeadStage::Contacted, 'whatsapp_sent', $conversation->id);

        Log::info('WhatsApp message sent.', ['organization_id' => $message->organization_id, 'message_id' => $message->id]);

        return $message;
    }

    private function customerFor(Conversation $conversation): Customer
    {
        return Customer::query()->where('organization_id', $conversation->organization_id)->findOrFail($conversation->customer_id);
    }
}
