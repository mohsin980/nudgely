<?php

namespace App\Services\Estimates\Import;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationStatus;
use App\Enums\ConversationStatus;
use App\Enums\EstimateStatus;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateImport;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts the follow-up sequence for imported estimates the business confirms were already sent to the customer
 * outside QuoteFlow.
 *
 * This sends no estimate email and does not change an estimate's status: a follow-up is only scheduled, and each
 * one is then sent by the existing follow-up engine (which re-checks opt-out, suppression, replies and expiry at send
 * time). Eligibility is recomputed on the server when activating, so the page's list is never trusted.
 */
class ImportFollowUpActivation
{
    /** Reasons an estimate is left out. Each is shown to the business with its count. */
    public const REASONS = [
        'already_activated' => 'Follow-ups already started',
        'changed_in_quoteflow' => 'Already sent or changed in QuoteFlow',
        'not_confirmed_sent' => 'The file does not say it was sent',
        'expired' => 'Expiration date has passed',
        'no_email' => 'No email address',
        'opted_out' => 'Customer opted out of email',
        'suppressed' => 'Email address bounced or complained',
    ];

    public function __construct(private readonly FollowUpService $followUps) {}

    /**
     * What activation would do: the eligible estimates, the reasons for the rest, and the sequence that would apply.
     *
     * @return array{eligible: Collection<int, Estimate>, excluded: array<string, int>, automation: ?Automation, steps: list<AutomationAction>, blocked: ?string}
     */
    public function plan(EstimateImport $import): array
    {
        $organization = $import->organization;
        [$automation, $steps, $blocked] = $this->sequence($organization);

        // The follow-up engine skips every automated follow-up while automations are off, so say so before scheduling.
        if (! config('automation.enabled') || ! $organization->automations_enabled) {
            $blocked = 'Automations are turned off for your business. Turn them on before starting follow-ups.';
        }

        $eligible = collect();
        $excluded = array_fill_keys(array_keys(self::REASONS), 0);
        $today = $this->today($organization);

        Estimate::query()->with('customer')->where('import_id', $import->id)->orderBy('id')->get()->each(function (Estimate $estimate) use (&$eligible, &$excluded, $today) {
            $reason = $this->reasonFor($estimate, $today);

            if ($reason === null) {
                $eligible->push($estimate);
            } else {
                $excluded[$reason]++;
            }
        });

        return [
            'eligible' => $eligible,
            'excluded' => array_filter($excluded),
            'automation' => $automation,
            'steps' => $steps,
            'blocked' => $blocked,
        ];
    }

    /**
     * Schedule the sequence for every eligible estimate. Safe to repeat: an estimate that already started is left out.
     *
     * @throws ValidationException
     */
    public function activate(User $actor, EstimateImport $import, bool $confirmed): int
    {
        Gate::forUser($actor)->authorize('create', Estimate::class);
        abort_unless($actor->organization_id === $import->organization_id, 404);

        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm that these estimates were already sent to the customers outside QuoteFlow.']);
        }

        $plan = $this->plan($import);

        if ($plan['blocked'] !== null) {
            throw ValidationException::withMessages(['sequence' => $plan['blocked']]);
        }

        if ($plan['eligible']->isEmpty()) {
            throw ValidationException::withMessages(['sequence' => 'No estimates are eligible for follow-ups.']);
        }

        // All or nothing: a failure part-way leaves no estimate half-started, so the page never shows a partial state.
        $scheduled = DB::transaction(function () use ($actor, $import, $plan) {
            $today = $this->today($import->organization);
            $messages = 0;

            foreach ($plan['eligible']->pluck('id') as $estimateId) {
                $messages += $this->activateOne($actor, $import, (int) $estimateId, $plan['automation'], $plan['steps'], $today);
            }

            return $messages;
        });

        Log::info('estimate_import.follow_ups_activated', ['event' => 'estimate_import.follow_ups_activated', 'organization_id' => $import->organization_id, 'estimate_import_id' => $import->id, 'estimates' => $plan['eligible']->count(), 'follow_ups' => $scheduled]);

        return $scheduled;
    }

    /**
     * @param  list<AutomationAction>  $steps
     */
    private function activateOne(User $actor, EstimateImport $import, int $estimateId, Automation $automation, array $steps, string $today): int
    {
        // Locked and checked again: another request may have changed the estimate since the page was shown.
        $estimate = Estimate::query()->with('customer')->whereKey($estimateId)->lockForUpdate()->firstOrFail();

        if ($this->reasonFor($estimate, $today) !== null) {
            return 0;
        }

        $conversation = $this->conversationFor($import->organization, $estimate->customer, $estimate);
        $count = 0;

        foreach ($steps as $step) {
            $config = $step->configuration ?? [];

            [, $created] = $this->followUps->scheduleAutomated(
                $automation,
                $conversation,
                now()->addDays((int) $config['delay_days']),
                (string) $config['subject'],
                (string) $config['body'],
                "import-follow-up:{$estimate->id}:{$step->id}",
                [
                    'estimate_id' => $estimate->id,
                    'automation_action_id' => $step->id,
                ],
            );

            $count += $created ? 1 : 0;
        }

        $estimate->forceFill(['follow_up_activated_at' => now(), 'follow_up_activated_by' => $actor->id])->save();

        return $count;
    }

    /**
     * Today's date in the business's timezone, as Y-m-d. Expiry is judged on the business's calendar, not the server's.
     */
    private function today(Organization $organization): string
    {
        return CarbonImmutable::now($organization->timezone())->toDateString();
    }

    /**
     * Null when the estimate may start follow-ups, otherwise the reason it may not.
     */
    private function reasonFor(Estimate $estimate, string $today): ?string
    {
        if ($estimate->follow_up_activated_at !== null) {
            return 'already_activated';
        }

        if ($estimate->status !== EstimateStatus::Draft) {
            return 'changed_in_quoteflow';
        }

        // Only what the file itself says was sent. A draft, or no status at all, was never sent.
        if (! in_array($estimate->imported_status, ['sent', 'viewed'], true)) {
            return 'not_confirmed_sent';
        }

        if ($estimate->valid_until !== null && $estimate->valid_until->toDateString() < $today) {
            return 'expired';
        }

        $customer = $estimate->customer;

        if ($customer === null || ! $customer->hasEmail()) {
            return 'no_email';
        }

        if ($customer->hasOptedOutOfEmail()) {
            return 'opted_out';
        }

        if ($customer->isEmailSuppressed()) {
            return 'suppressed';
        }

        return null;
    }

    /**
     * The organization's active automation with its email follow-up steps, or why there is none.
     *
     * @return array{0: ?Automation, 1: list<AutomationAction>, 2: ?string}
     */
    private function sequence(Organization $organization): array
    {
        $automation = Automation::query()->forOrganization($organization)
            ->where('status', AutomationStatus::Active)
            ->whereHas('actions', fn ($q) => $q->where('type', AutomationActionType::ScheduleFollowUp))
            ->orderBy('id')
            ->first();

        if ($automation === null) {
            return [null, [], 'Turn on a follow-up automation first. It sets the delays and messages for the sequence.'];
        }

        $steps = $automation->actions
            ->filter(fn (AutomationAction $action) => $action->type === AutomationActionType::ScheduleFollowUp && ($action->configuration['kind'] ?? 'email') === 'email')
            ->values()
            ->all();

        $valid = array_filter($steps, fn (AutomationAction $step) => is_int($step->configuration['delay_days'] ?? null)
            && filled($step->configuration['subject'] ?? null) && filled($step->configuration['body'] ?? null));

        if ($valid === []) {
            return [$automation, [], "The automation \"{$automation->name}\" has no email follow-up steps to start."];
        }

        return [$automation, array_values($valid), null];
    }

    private function conversationFor(Organization $organization, Customer $customer, Estimate $estimate): Conversation
    {
        $open = $organization->conversations()->where('customer_id', $customer->id)->where('status', '!=', ConversationStatus::Closed)->latest('id')->first();

        if ($open !== null) {
            return $open;
        }

        $conversation = new Conversation;
        $conversation->forceFill([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => Str::limit("Estimate {$estimate->displayNumber()}: {$estimate->title}", 200, ''),
            'status' => ConversationStatus::Open,
        ])->save();

        return $conversation;
    }
}
