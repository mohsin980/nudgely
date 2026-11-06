<?php

namespace App\Services\Customers;

use App\Enums\CustomerStatus;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a customer's Active / Inactive status in step with whether they respond:
 * no response for a while after the last follow-up email of a chain makes them inactive,
 * and any reply makes them active again.
 */
class CustomerActivityService
{
    public const REASON_NO_REPLY = 'no_reply';

    public const REASON_MANUAL = 'manual';

    /**
     * Mark customers inactive who haven't responded since the last follow-up email of a chain,
     * sent at least "inactive_after_days" ago. Looks back one extra week so a missed run catches up,
     * but never further, so someone made active again by hand isn't switched off by an old email.
     *
     * @return int How many customers were marked inactive.
     */
    public function markUnresponsive(): int
    {
        $days = (int) config('follow_ups.inactive_after_days');
        $before = now()->subDays($days);

        $candidates = FollowUp::query()
            ->where('final_attempt', true)
            ->where('type', FollowUpType::Automated)
            ->where('status', FollowUpStatus::Completed)
            ->whereNotNull('message_id')
            ->where('completed_at', '<=', $before)
            ->where('completed_at', '>', $before->copy()->subDays(7))
            ->orderBy('completed_at')
            ->limit((int) config('follow_ups.batch_size'))
            ->get(['id', 'organization_id', 'customer_id', 'conversation_id', 'completed_at']);

        return $candidates->filter(fn (FollowUp $lastEmail) => $this->markInactiveIfUnresponsive($lastEmail, $days))->count();
    }

    /**
     * A reply makes an inactive customer active again straight away.
     */
    public function reactivateOnReply(int $organizationId, int $customerId, int $conversationId): bool
    {
        return DB::transaction(function () use ($organizationId, $customerId, $conversationId) {
            $customer = Customer::query()->where('organization_id', $organizationId)->lockForUpdate()->find($customerId);

            if ($customer === null || $customer->status !== CustomerStatus::Inactive) {
                return false;
            }

            $customer->forceFill(['status' => CustomerStatus::Active, 'inactive_reason' => null, 'status_changed_at' => now()])->save();

            if (($conversation = Conversation::query()->where('organization_id', $organizationId)->find($conversationId)) !== null) {
                ConversationEvent::record($conversation, 'customer_reactivated', null, ['by' => 'reply']);
            }

            Log::info('Customer active again after replying.', ['organization_id' => $organizationId, 'customer_id' => $customerId]);

            return true;
        });
    }

    private function markInactiveIfUnresponsive(FollowUp $lastEmail, int $days): bool
    {
        return DB::transaction(function () use ($lastEmail, $days) {
            $customer = Customer::query()->where('organization_id', $lastEmail->organization_id)->lockForUpdate()->find($lastEmail->customer_id);
            $since = $lastEmail->completed_at;

            if ($customer === null
                || $customer->status !== CustomerStatus::Active
                || ($customer->status_changed_at !== null && $customer->status_changed_at->greaterThan($since)) // someone changed it since
                || $this->hasNewerFollowUp($lastEmail)
                || $this->repliedSince($customer, $since)
                || $this->engagedWithEstimateSince($customer, $since)) {
                return false;
            }

            $customer->forceFill(['status' => CustomerStatus::Inactive, 'inactive_reason' => self::REASON_NO_REPLY, 'status_changed_at' => now()])->save();

            if (($conversation = Conversation::query()->where('organization_id', $customer->organization_id)->find($lastEmail->conversation_id)) !== null) {
                ConversationEvent::record($conversation, 'customer_inactive', null, ['reason' => self::REASON_NO_REPLY, 'days' => $days]);
            }

            Log::info('Customer marked inactive: no reply after follow-ups.', ['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'follow_up_id' => $lastEmail->id]);

            return true;
        });
    }

    /**
     * A later follow-up (open, or sent after this one) means the business is still following up.
     */
    private function hasNewerFollowUp(FollowUp $lastEmail): bool
    {
        return FollowUp::query()
            ->where('organization_id', $lastEmail->organization_id)
            ->where('customer_id', $lastEmail->customer_id)
            ->whereKeyNot($lastEmail->id)
            ->where(fn ($q) => $q->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due])
                ->orWhere(fn ($q) => $q->where('status', FollowUpStatus::Completed)->where('completed_at', '>', $lastEmail->completed_at)))
            ->exists();
    }

    private function repliedSince(Customer $customer, $since): bool
    {
        return Message::query()
            ->where('organization_id', $customer->organization_id)
            ->where('direction', MessageDirection::Inbound)
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->id))
            ->where('received_at', '>', $since)
            ->exists();
    }

    /**
     * Viewing, accepting or declining an estimate is a response too.
     */
    private function engagedWithEstimateSince(Customer $customer, $since): bool
    {
        return Estimate::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('viewed_at', '>', $since)->orWhere('accepted_at', '>', $since)->orWhere('declined_at', '>', $since))
            ->exists();
    }
}
