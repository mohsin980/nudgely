<?php

namespace App\Services\Email\Bounces;

use App\Enums\MessageStatus;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records a permanent bounce on a sent message and stops emailing that address.
 *
 * Used by provider delivery events (Postmark) and by bounce notices read from the business's own mailbox or
 * received at the reply domain (SMTP). Every change goes through MessageStatus::canTransitionTo.
 */
class BounceRecorder
{
    public const RECORDED = 'recorded';

    public const ALREADY_RECORDED = 'already_recorded';

    public const IGNORED = 'ignored';

    public function recordPermanentBounce(Message $message, string $source): string
    {
        if ($message->status === MessageStatus::Bounced) {
            return self::ALREADY_RECORDED;
        }

        // A send that was recorded as failed ("outcome unknown") may still have bounced: provider evidence may override that.
        if (! $message->status->canTransitionTo(MessageStatus::Bounced, providerEvidence: true)) {
            return self::IGNORED;
        }

        $reconciling = $message->status === MessageStatus::Failed;

        $message->forceFill(array_merge(
            ['status' => MessageStatus::Bounced, 'bounced_at' => now(), 'updated_at' => now()],
            $reconciling ? ['failed_at' => null, 'failure_reason' => null] : [],
        ))->save();

        $this->suppress($message, MessageStatus::Bounced);

        Log::info('email.bounced', [
            'event' => 'email.bounced',
            'organization_id' => $message->organization_id,
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'provider' => $message->provider?->value,
            'source' => $source,
            'correlation_id' => $message->correlation_id,
        ]);

        return self::RECORDED;
    }

    /**
     * A bounced or complained address is not emailed again until the customer's address changes.
     * The customer record itself is kept.
     */
    public function suppress(Message $message, MessageStatus $reason): void
    {
        if ($message->conversation_id === null) {
            return;
        }

        $customer = Customer::query()
            ->where('organization_id', $message->organization_id)
            ->whereIn('id', DB::table('conversations')->where('id', $message->conversation_id)->select('customer_id'))
            ->first();

        if ($customer === null || strtolower((string) $customer->email) !== strtolower((string) $message->to_address)) {
            return;
        }

        $customer->forceFill([
            'email_suppressed_address' => strtolower((string) $customer->email),
            'email_suppression_reason' => $reason->value,
            'email_suppressed_at' => now(),
        ])->save();
    }
}
