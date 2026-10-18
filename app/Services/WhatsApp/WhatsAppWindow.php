<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Meta lets a business send free-form messages only within 24 hours of the customer's last message.
 * Outside that window, only approved templates may be sent. The window belongs to the customer's
 * number, so it is measured across all of that customer's conversations.
 */
class WhatsAppWindow
{
    public function lastInboundAt(Customer $customer): ?CarbonImmutable
    {
        $receivedAt = Message::query()
            ->where('organization_id', $customer->organization_id)
            ->where('channel', MessageChannel::WhatsApp)
            ->where('direction', MessageDirection::Inbound)
            ->whereHas('conversation', fn ($query) => $query->where('customer_id', $customer->id))
            ->max('received_at');

        return $receivedAt === null ? null : CarbonImmutable::parse($receivedAt);
    }

    public function isOpen(Customer $customer): bool
    {
        $lastInboundAt = $this->lastInboundAt($customer);

        return $lastInboundAt !== null && $lastInboundAt->gt(now()->subHours((int) config('whatsapp.window_hours', 24)));
    }
}
