<?php

namespace App\Services\WhatsApp;

use App\Enums\Billing\LimitKey;
use App\Enums\ConversationStatus;
use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\CustomerCreated;
use App\Events\CustomerReplyReceived;
use App\Exceptions\Billing\PlanLimitException;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\AI\CustomerReplyClassificationService;
use App\Services\Billing\EntitlementService;
use App\Services\Customers\CustomerService;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a WhatsApp webhook into QuoteFlow records, for the business whose number it was sent to.
 *
 * Customer messages: the customer is found by phone number (or added as a new lead, if the plan allows),
 * the message joins their open conversation (or starts one), and it is announced as a customer reply, so
 * follow-ups stop, inactive customers become active and lead stages move, exactly as for email. Writing
 * to the business counts as agreeing to WhatsApp messages. Retried deliveries are stored once.
 *
 * Status updates: Meta reports delivered, read and failed for the business's own messages.
 */
class WhatsAppInboundProcessor
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int how many customer messages were stored
     */
    public function process(WhatsAppConnection $connection, array $payload): int
    {
        $stored = 0;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);

                // Only events for this business's number: the signature proved who sent them, this proves where.
                if ((string) data_get($value, 'metadata.phone_number_id') !== $connection->phone_number_id) {
                    continue;
                }

                $names = collect((array) ($value['contacts'] ?? []))->mapWithKeys(fn ($contact) => [(string) data_get($contact, 'wa_id') => (string) data_get($contact, 'profile.name')]);

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $stored += $this->storeMessage($connection, (array) $message, $names->get((string) ($message['from'] ?? ''))) ? 1 : 0;
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->applyStatus($connection, (array) $status);
                }
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeMessage(WhatsAppConnection $connection, array $data, ?string $profileName): bool
    {
        $id = (string) ($data['id'] ?? '');
        $phone = PhoneNumber::toE164('+'.preg_replace('/\D+/', '', (string) ($data['from'] ?? '')));

        if ($id === '' || $phone === null) {
            return false;
        }

        if (Message::query()->where('channel', MessageChannel::WhatsApp)->where('provider_message_id', $id)->where('direction', MessageDirection::Inbound)->exists()) {
            return false; // a retried delivery
        }

        try {
            return DB::transaction(function () use ($connection, $data, $id, $phone, $profileName) {
                $organization = $connection->organization;
                [$customer, $created] = $this->customerFor($organization, $phone, $profileName);

                if ($customer === null) {
                    return false;
                }

                $conversation = $this->conversationFor($customer);
                $receivedAt = now();

                $message = new Message;
                $message->forceFill([
                    'organization_id' => $organization->id,
                    'conversation_id' => $conversation->id,
                    'direction' => MessageDirection::Inbound,
                    'channel' => MessageChannel::WhatsApp,
                    'provider' => null,
                    'from_address' => $phone,
                    'from_name' => $profileName ? mb_substr($profileName, 0, 255) : null,
                    'to_address' => $connection->display_phone_number ?: $connection->phone_number_id,
                    'subject' => mb_substr((string) $conversation->subject, 0, 998),
                    'body_text' => $this->text($data),
                    'provider_message_id' => $id,
                    'status' => MessageStatus::Received,
                    'received_at' => $receivedAt,
                    'metadata' => ['whatsapp_type' => (string) ($data['type'] ?? 'text')],
                ])->save();

                $conversation->recordActivity($receivedAt);

                if ($created) {
                    CustomerCreated::dispatch($organization->id, $customer->id);
                }

                event(new CustomerReplyReceived($organization->id, $message->id, $conversation->id, $customer->id));

                if (config('ai.classification.enabled') && $message->body_text !== null) {
                    ClassifyCustomerReplyJob::dispatch($message->id, CustomerReplyClassificationService::automaticRequestId($message))->afterCommit();
                }

                Log::info('WhatsApp message received.', ['organization_id' => $organization->id, 'conversation_id' => $conversation->id, 'message_id' => $message->id, 'new_customer' => $created]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false; // the same message, stored by a parallel delivery
        }
    }

    /**
     * The customer with this number, or a new lead named from their WhatsApp profile. Messaging the business
     * first is consent to WhatsApp replies.
     *
     * @return array{0: ?Customer, 1: bool} the customer (null if the plan's limit stops a new one) and whether it is new
     */
    private function customerFor($organization, string $phone, ?string $profileName): array
    {
        $customer = Customer::query()->where('organization_id', $organization->id)->where('phone_e164', $phone)->orderBy('id')->first();

        if ($customer !== null) {
            if ($customer->whatsapp_opt_in_at === null) {
                $customer->forceFill(['whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => CustomerService::CONSENT_INBOUND])->save();
            }

            return [$customer, false];
        }

        try {
            $customer = $this->entitlements->guard($organization, LimitKey::Customers, function () use ($organization, $phone, $profileName) {
                $customer = new Customer;
                $customer->forceFill([
                    'organization_id' => $organization->id,
                    'name' => trim((string) $profileName) !== '' ? mb_substr(trim($profileName), 0, 200) : $phone,
                    'phone' => $phone,
                    'status' => CustomerStatus::Active,
                    'preferred_channel' => MessageChannel::WhatsApp,
                    'whatsapp_opt_in_at' => now(),
                    'whatsapp_opt_in_source' => CustomerService::CONSENT_INBOUND,
                ])->save();

                return $customer;
            });
        } catch (PlanLimitException) {
            Log::warning('WhatsApp message from a new number not stored: customer limit reached.', ['organization_id' => $organization->id]);

            return [null, false];
        }

        return [$customer, true];
    }

    private function conversationFor(Customer $customer): Conversation
    {
        $open = Conversation::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', ConversationStatus::Closed)
            ->latest('id')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $conversation = new Conversation;
        $conversation->forceFill([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'subject' => 'WhatsApp chat',
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ])->save();

        return $conversation;
    }

    /**
     * Readable text for any message type. Media itself isn't downloaded yet; its caption is kept.
     *
     * @param  array<string, mixed>  $data
     */
    private function text(array $data): string
    {
        $type = (string) ($data['type'] ?? 'text');

        $text = match ($type) {
            'text' => (string) data_get($data, 'text.body'),
            'button' => (string) data_get($data, 'button.text'),
            'interactive' => (string) (data_get($data, 'interactive.button_reply.title') ?? data_get($data, 'interactive.list_reply.title')),
            'image', 'video', 'document' => trim('['.ucfirst($type).'] '.data_get($data, $type.'.caption', '')),
            'audio' => '[Voice message]',
            'location' => '[Location] '.data_get($data, 'location.name', ''),
            'sticker' => '[Sticker]',
            default => '['.ucfirst($type).']',
        };

        return mb_substr(trim($text) !== '' ? trim($text) : '['.ucfirst($type).']', 0, 10000);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(WhatsAppConnection $connection, array $status): void
    {
        $message = Message::query()
            ->where('organization_id', $connection->organization_id)
            ->where('channel', MessageChannel::WhatsApp)
            ->where('direction', MessageDirection::Outbound)
            ->where('provider_message_id', (string) ($status['id'] ?? ''))
            ->first();

        if ($message === null) {
            return;
        }

        match ((string) ($status['status'] ?? '')) {
            'delivered' => $message->status === MessageStatus::Sent ? $message->forceFill(['status' => MessageStatus::Delivered])->save() : null,
            'read' => $message->forceFill([
                'status' => $message->status === MessageStatus::Failed ? MessageStatus::Failed : MessageStatus::Delivered,
                'metadata' => array_merge($message->metadata ?? [], ['whatsapp_read_at' => now()->toIso8601String()]),
            ])->save(),
            'failed' => $message->forceFill([
                'status' => MessageStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => mb_substr((string) (data_get($status, 'errors.0.error_data.details') ?: data_get($status, 'errors.0.title') ?: 'WhatsApp could not deliver it.'), 0, 255),
            ])->save(),
            default => null,
        };
    }
}
