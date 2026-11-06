<?php

namespace App\Services\Leads;

use App\Enums\EstimateStatus;
use App\Enums\LeadStage;
use App\Models\ConversationEvent;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves customers through the lead stages.
 *
 * Automatic changes only ever move forward (New → Contacted → Quoted → Won / Lost) and never
 * leave Won or Lost. A person can set any stage by hand.
 */
class LeadStageService
{
    /**
     * An automatic step forward, e.g. "an estimate was sent" → Quoted. Returns whether it changed.
     */
    public function advance(int $organizationId, ?int $customerId, LeadStage $to, string $reason, ?int $conversationId = null): bool
    {
        if ($customerId === null) {
            return false;
        }

        return DB::transaction(function () use ($organizationId, $customerId, $to, $reason, $conversationId) {
            $customer = Customer::query()->where('organization_id', $organizationId)->lockForUpdate()->find($customerId);

            if ($customer === null || $customer->lead_stage->isClosed() || $to->rank() <= $customer->lead_stage->rank()) {
                return false;
            }

            $this->change($customer, $to, ['by' => $reason], null, $conversationId);

            return true;
        });
    }

    /**
     * A declined estimate makes the lead Lost, unless another estimate is still waiting for an answer.
     */
    public function estimateDeclined(int $organizationId, ?int $customerId, int $estimateId, ?int $conversationId): bool
    {
        $stillOpen = Estimate::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->whereKeyNot($estimateId)
            ->whereIn('status', [EstimateStatus::Sent, EstimateStatus::Viewed])
            ->exists();

        return ! $stillOpen && $this->advance($organizationId, $customerId, LeadStage::Lost, 'estimate_declined', $conversationId);
    }

    /**
     * Set by a person: any stage, any direction.
     */
    public function set(User $actor, Customer $customer, LeadStage $to): Customer
    {
        abort_unless($actor->organization_id !== null && $actor->organization_id === $customer->organization_id, 404);

        return DB::transaction(function () use ($actor, $customer, $to) {
            $locked = Customer::query()->where('organization_id', $customer->organization_id)->lockForUpdate()->findOrFail($customer->id);

            if ($locked->lead_stage !== $to) {
                $this->change($locked, $to, ['by' => 'manual'], $actor);
            }

            return $locked;
        });
    }

    private function change(Customer $customer, LeadStage $to, array $data, ?User $actor = null, ?int $conversationId = null): void
    {
        $from = $customer->lead_stage;
        $customer->forceFill(['lead_stage' => $to, 'lead_stage_changed_at' => now()])->save();

        $event = new ConversationEvent;
        $event->forceFill([
            'organization_id' => $customer->organization_id,
            'conversation_id' => $conversationId,
            'customer_id' => $customer->id,
            'user_id' => $actor?->id,
            'type' => 'lead_stage_changed',
            'data' => ['from' => $from->value, 'to' => $to->value] + $data,
        ])->save();

        Log::info('Lead stage changed.', ['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'from' => $from->value, 'to' => $to->value] + $data);
    }
}
