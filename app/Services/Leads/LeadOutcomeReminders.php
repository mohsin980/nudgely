<?php

namespace App\Services\Leads;

use App\Enums\CustomerStatus;
use App\Enums\LeadStage;
use App\Enums\Team\NotificationType;
use App\Models\Customer;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reminds the business to record how a lead ended. A lead that has been Qualified or Quoted for a week (config
 * leads.outcome_reminders) without becoming Won or Lost reminds the person handling it, or the owner and managers
 * when nobody is, then again each week, at most three times. Changing the lead stage starts the count again.
 */
class LeadOutcomeReminders
{
    public function __construct(
        private readonly TeamNotifier $notifier,
        private readonly TeamDirectory $team,
    ) {}

    /**
     * @return int how many leads were reminded about
     */
    public function send(): int
    {
        $config = (array) config('leads.outcome_reminders');
        $after = now()->subDays((int) ($config['after_days'] ?? 7));
        $every = now()->subDays((int) ($config['every_days'] ?? 7));
        $max = (int) ($config['max'] ?? 3);
        $sent = 0;

        Customer::query()
            ->whereIn('lead_stage', [LeadStage::Qualified, LeadStage::Quoted])
            ->where('lead_stage_changed_at', '<=', $after)
            ->where('status', CustomerStatus::Active)
            ->where('is_demo', false)
            ->where('outcome_reminders', '<', $max)
            ->where(fn ($query) => $query->whereNull('outcome_reminded_at')->orWhere('outcome_reminded_at', '<=', $every))
            ->with(['organization', 'assignee'])
            ->chunkById(200, function ($customers) use (&$sent) {
                foreach ($customers as $customer) {
                    try {
                        $sent += $this->remind($customer) ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        Log::info('Won / Lost reminders sent.', ['leads' => $sent]);

        return $sent;
    }

    private function remind(Customer $customer): bool
    {
        $number = $customer->outcome_reminders + 1;
        // Claim this reminder first, so a second scheduler run can't send it again.
        $claimed = Customer::query()->whereKey($customer->id)->where('outcome_reminders', $customer->outcome_reminders)
            ->update(['outcome_reminders' => $number, 'outcome_reminded_at' => now()]);

        if ($claimed === 0 || $customer->organization === null || $customer->organization->isSuspended()) {
            return false;
        }

        $recipients = $customer->assignee?->isActiveMember() ? collect([$customer->assignee]) : $this->team->leaders($customer->organization_id);
        $days = (int) $customer->lead_stage_changed_at->diffInDays(now());

        $this->notifier->notify($customer->organization, NotificationType::LeadOutcomeReminder, $recipients,
            'Did '.$customer->name.' go ahead? They have been '.$customer->lead_stage->label().' for '.$days.' days: mark the lead Won or Lost.',
            route('customers.show', $customer->id), 'lead-outcome:'.$customer->id.':'.$customer->lead_stage->value.':'.$number,
            data: ['customer_id' => $customer->id]);

        return true;
    }
}
