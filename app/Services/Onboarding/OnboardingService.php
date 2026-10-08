<?php

namespace App\Services\Onboarding;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationConditionOperator;
use App\Enums\Automation\AutomationConditionType;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\CustomerStatus;
use App\Enums\Onboarding\OnboardingStep;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Estimates\EstimateException;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Automation\AutomationBuilder;
use App\Services\Automation\AutomationTemplates;
use App\Services\Billing\EntitlementService;
use App\Services\Customers\CustomerService;
use App\Services\Estimates\EstimateService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * First-run onboarding for a business owner. It owns no business logic of its own: every save goes
 * through the existing services (business settings, customers, estimates, automations, email), and
 * progress is read from what really exists. Only the owner of the organization they belong to can
 * act; the organization always comes from the acting user, never from input.
 *
 * Progress is stored on the organization (onboarding_step, onboarding_progress, timestamps):
 * "done" is derived from real records where there are some (a verified email connection, a
 * customer, an estimate, an automation) so it can't drift; the progress map only remembers what
 * has no record of its own (location confirmed) and what was skipped.
 */
class OnboardingService
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly CustomerService $customers,
        private readonly EstimateService $estimates,
        private readonly AutomationBuilder $automations,
        private readonly AutomationTemplates $templates,
        private readonly EntitlementService $entitlements,
    ) {}

    // ─── State ───────────────────────────────────────────────────────────────────────────

    /**
     * A new business starts onboarding when it signs up (called by registration).
     */
    public function begin(Organization $organization): void
    {
        $organization->forceFill(['onboarding_step' => OnboardingStep::BusinessProfile->value, 'onboarding_started_at' => now()])->save();
    }

    /**
     * Should this person be taken to onboarding? Only the owner of a business that started it and
     * neither finished nor left it. Invited members and finished businesses never are.
     */
    public function needs(User $user): bool
    {
        $organization = $user->organization;

        return $user->isOwner() && $organization !== null && $organization->onboarding_started_at !== null
            && $organization->onboarding_completed_at === null && $organization->onboarding_skipped_at === null;
    }

    public function state(Organization $organization): OnboardingState
    {
        $progress = $organization->onboarding_progress ?? [];
        $steps = [];
        $current = OnboardingStep::Completed;

        foreach (OnboardingStep::working() as $step) {
            $status = $this->isDone($organization, $step) ? 'done' : (($progress[$step->value] ?? null) === 'skipped' ? 'skipped' : 'pending');
            $steps[$step->value] = $status;

            if ($status === 'pending' && $current === OnboardingStep::Completed) {
                $current = $step;
            }
        }

        if ($organization->onboarding_step !== $current->value && $organization->onboarding_started_at !== null) {
            Organization::query()->whereKey($organization->id)->update(['onboarding_step' => $current->value]);
            $organization->onboarding_step = $current->value;
        }

        return new OnboardingState($steps, $current);
    }

    private function isDone(Organization $organization, OnboardingStep $step): bool
    {
        return match ($step) {
            OnboardingStep::BusinessProfile => filled($organization->business_type),
            OnboardingStep::BusinessPreferences => ($organization->onboarding_progress[$step->value] ?? null) === 'done',
            OnboardingStep::EmailConnection => $organization->emailConnections()->default()->verified()->exists(),
            OnboardingStep::FirstCustomer => $organization->customers()->where('is_demo', false)->exists(),
            OnboardingStep::FirstEstimate => $organization->estimates()->exists(),
            OnboardingStep::FirstAutomation => $organization->automations()->where('status', '!=', AutomationStatus::Archived)->exists(),
            OnboardingStep::Completed => false,
        };
    }

    // ─── Business ────────────────────────────────────────────────────────────────────────

    /**
     * Name, type, website and phone, saved through the business settings service.
     *
     * @param  array{name?: string, business_type?: string, website?: string, phone?: string}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function saveBusinessProfile(User $actor, array $input): Organization
    {
        $organization = $this->ownerOrganization($actor);

        Validator::make($input, ['business_type' => ['required', Rule::in(array_keys(config('onboarding.business_types')))]],
            ['business_type.required' => 'Choose the kind of business you run.', 'business_type.in' => 'Choose the kind of business you run.'])->validate();

        $website = trim((string) ($input['website'] ?? ''));

        if ($website !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $website)) {
            $website = 'https://'.$website;
        }

        $saved = $this->settings->updateProfile($actor, [
            'name' => $input['name'] ?? $organization->name,
            'business_type' => $input['business_type'],
            'website' => $website,
            'phone' => $input['phone'] ?? '',
            'country' => $organization->country ?? 'US',
        ]);

        return $saved;
    }

    /**
     * Country, state, city and timezone. A full address isn't needed.
     *
     * @param  array{country?: string, state?: string, city?: string, timezone?: string}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function saveLocation(User $actor, array $input): Organization
    {
        $organization = $this->ownerOrganization($actor);

        $this->settings->updateProfile($actor, [
            'name' => $organization->name,
            'country' => $input['country'] ?? $organization->country ?? 'US',
            'state' => $input['state'] ?? '',
            'city' => $input['city'] ?? '',
        ]);

        $organization = $this->settings->updatePreferences($actor, [
            'timezone' => $input['timezone'] ?? $organization->timezone(),
            'currency' => $organization->currencyCode(),
            'date_format' => $organization->date_format,
            'time_format' => $organization->time_format,
            'task_priority' => $organization->businessSettings()->taskPriority()->value,
        ]);

        $this->mark($organization, OnboardingStep::BusinessPreferences, 'done');

        return $organization;
    }

    /**
     * The timezone a business in this state most likely uses (a suggestion; always editable).
     */
    public function suggestedTimezone(string $country, ?string $state): ?string
    {
        return $country === 'US' ? (config('onboarding.state_timezones')[strtoupper((string) $state)] ?? null) : null;
    }

    // ─── Customer ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{name?: string, email?: string, phone?: string, company?: string}  $input
     *
     * @throws AuthorizationException|ValidationException|PlanLimitException
     */
    public function createFirstCustomer(User $actor, array $input): Customer
    {
        $this->ownerOrganization($actor);
        $name = trim(preg_replace('/\s+/', ' ', (string) ($input['name'] ?? '')));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the customer\'s name.']);
        }

        try {
            return $this->customers->create($actor, [
                'first_name' => Str::before($name, ' '),
                'last_name' => Str::contains($name, ' ') ? Str::after($name, ' ') : '',
                'email' => $input['email'] ?? '',
                'phone' => $input['phone'] ?? '',
                'company' => $input['company'] ?? '',
            ]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [in_array($field, ['first_name', 'last_name']) ? 'name' : $field => $messages[0]])->all());
        }
    }

    /**
     * A clearly marked demo customer for exploring. It can never be emailed, never triggers
     * automations and doesn't count toward the plan. One per business.
     */
    public function createSampleCustomer(User $actor): Customer
    {
        $organization = $this->ownerOrganization($actor);

        $existing = $organization->customers()->where('is_demo', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $customer = new Customer;
        $customer->forceFill([
            'organization_id' => $organization->id,
            'first_name' => 'Sample',
            'last_name' => 'Customer',
            'name' => 'Sample Customer',
            'email' => 'sample-'.Str::lower(Str::random(10)).'@example.invalid',
            'company' => 'Sample data (not a real customer)',
            'notes' => 'This is sample data created during setup. It never receives real emails. You can delete it any time.',
            'status' => CustomerStatus::Active,
            'is_demo' => true,
        ])->save();

        OrganizationActivity::record($organization, 'sample_data_created', $actor);

        return $customer;
    }

    /**
     * Delete the sample customer and what was created for it. Real data is never touched.
     *
     * @return int Sample customers removed.
     */
    public function removeSampleData(User $actor): int
    {
        $organization = $this->ownerOrganization($actor);

        return DB::transaction(function () use ($organization, $actor) {
            $demo = $organization->customers()->where('is_demo', true)->lockForUpdate()->get();

            foreach ($demo as $customer) {
                $estimateIds = $organization->estimates()->where('customer_id', $customer->id)->pluck('id');
                $organization->followUps()->where('customer_id', $customer->id)->delete();
                $organization->tasks()->where('customer_id', $customer->id)->delete();
                $organization->conversations()->where('customer_id', $customer->id)->each(fn ($conversation) => $conversation->delete());
                Estimate::query()->whereIn('id', $estimateIds)->each(fn (Estimate $estimate) => $estimate->delete());
                $customer->delete();
            }

            if ($demo->isNotEmpty()) {
                OrganizationActivity::record($organization, 'sample_data_removed', $actor, ['customers' => $demo->count()]);
            }

            return $demo->count();
        });
    }

    // ─── Estimate ────────────────────────────────────────────────────────────────────────

    /**
     * What a new estimate starts with, from the business's own defaults.
     *
     * @return array{valid_until: string, notes: string, tax_rate: string, currency: string, business: string}
     */
    public function estimateDefaults(Organization $organization): array
    {
        $defaults = $organization->businessSettings();

        return [
            'valid_until' => $organization->localNow()->addDays($defaults->estimateValidDays())->toDateString(),
            'notes' => $defaults->estimateNotes() ?? '',
            'tax_rate' => $defaults->estimateTaxRate() ?? '',
            'currency' => $organization->currencyCode(),
            'business' => $organization->name,
        ];
    }

    /**
     * A draft estimate (not sent) through the normal estimate service.
     *
     * @param  array{customer_id?: int|string, title?: string, description?: string, quantity?: string, unit_price?: string}  $input
     *
     * @throws AuthorizationException|EstimateException
     */
    public function createFirstEstimate(User $actor, array $input): Estimate
    {
        $organization = $this->ownerOrganization($actor);
        $defaults = $this->estimateDefaults($organization);
        $title = trim((string) ($input['title'] ?? ''));

        return $this->estimates->create($actor, [
            'customer_id' => (string) ($input['customer_id'] ?? ''),
            'conversation_id' => '',
            'title' => $title,
            'notes' => $defaults['notes'],
            'valid_until' => $defaults['valid_until'],
            'discount_type' => '',
            'discount_value' => '',
            'tax_rate' => $defaults['tax_rate'],
            'items' => [[
                'description' => trim((string) ($input['description'] ?? '')) ?: $title,
                'quantity' => (string) ($input['quantity'] ?? '1'),
                'unit_price' => (string) ($input['unit_price'] ?? ''),
            ]],
        ]);
    }

    // ─── Automation ──────────────────────────────────────────────────────────────────────

    /**
     * Templates suggested for this kind of business (data from config/onboarding.php), first one first.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public function suggestedTemplates(Organization $organization): array
    {
        $types = config('onboarding.business_types');
        $keys = $types[$organization->business_type]['templates'] ?? $types['other']['templates'];
        $all = AutomationTemplates::all();
        $result = [];

        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $result[$key] = ['name' => $all[$key]['name'], 'description' => $all[$key]['description']];
            }
        }

        return $result;
    }

    /**
     * Everything the owner should see before an automation can email customers.
     *
     * @return array{name: string, trigger: string, delay: ?string, conditions: list<string>, actions: list<string>, sender: ?string, recipient: string, sends_email: bool, unattended: bool, approval: bool}
     */
    public function review(User $actor, string $templateKey): array
    {
        $organization = $this->ownerOrganization($actor);
        $template = AutomationTemplates::all()[$templateKey] ?? abort(404);
        $sender = $organization->emailConnections()->default()->verified()->first()?->sender_email;
        $sendsEmail = collect($template['actions'])->contains(fn ($a) => in_array($a['type'], ['send_email', 'schedule_follow_up'], true));

        return [
            'name' => $template['name'],
            'trigger' => AutomationTriggerType::from($template['trigger_type'])->label(),
            'delay' => Automation::describeWait($template['wait_minutes'] ?? null) ?? collect($template['actions'])->map(fn ($a) => isset($a['configuration']['delay_days']) ? $a['configuration']['delay_days'].' days' : null)->filter()->first(),
            'conditions' => collect($template['conditions'])->map(fn ($c) => $this->describeCondition($c))->all(),
            'actions' => collect($template['actions'])->map(fn ($a) => AutomationActionType::from($a['type'])->label())->all(),
            'sender' => $sendsEmail ? $sender : null,
            'recipient' => $sendsEmail ? 'the customer\'s email address' : '',
            'sends_email' => $sendsEmail,
            'unattended' => $organization->allowsUnattendedAutomatedEmail(),
            'approval' => collect($template['actions'])->contains(fn ($a) => ($a['requires_approval'] ?? true) === true && in_array($a['type'], ['send_email', 'schedule_follow_up'], true)),
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function describeCondition(array $condition): string
    {
        $label = AutomationConditionType::from($condition['type'])->label();
        $operator = AutomationConditionOperator::from($condition['operator']);

        return match ($operator->value) {
            'is_true' => $label,
            'is_false' => str_contains($label, ' has ') ? Str::replaceFirst(' has ', ' has not ', $label) : 'Not: '.$label,
            default => trim("{$label} {$operator->label()} {$condition['value']}"),
        };
    }

    /**
     * Add the template as a draft the owner can customize. Nothing is activated.
     *
     * @throws AuthorizationException
     */
    public function installTemplate(User $actor, string $templateKey): Automation
    {
        return $this->draftOf($this->ownerOrganization($actor), $actor, $templateKey);
    }

    /**
     * Install and activate a template, only after the owner confirmed having seen the review.
     *
     * @throws AuthorizationException|ValidationException|PlanLimitException
     */
    public function activateTemplate(User $actor, string $templateKey, bool $confirmed): Automation
    {
        $organization = $this->ownerOrganization($actor);

        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm the details above to activate this automation.']);
        }

        $automation = $this->draftOf($organization, $actor, $templateKey);

        $this->automations->activate($automation, $actor);
        OrganizationActivity::record($organization, 'onboarding_automation_activated', $actor, ['template' => $templateKey]);

        return $automation->refresh();
    }

    // ─── Skipping and finishing ──────────────────────────────────────────────────────────

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function skipStep(User $actor, OnboardingStep $step): void
    {
        $organization = $this->ownerOrganization($actor);

        if ($step->isRequired() || $step === OnboardingStep::Completed) {
            throw ValidationException::withMessages(['step' => 'This step can\'t be skipped.']);
        }

        $this->mark($organization, $step, 'skipped');
    }

    /**
     * "Skip for now": leave onboarding without finishing. Nothing is created or changed; the
     * dashboard shows what is left.
     */
    public function skipOnboarding(User $actor): void
    {
        $organization = $this->ownerOrganization($actor);

        if ($organization->onboarding_completed_at === null && $organization->onboarding_skipped_at === null) {
            $organization->forceFill(['onboarding_skipped_at' => now()])->save();
            OrganizationActivity::record($organization, 'onboarding_skipped', $actor);
        }
    }

    /**
     * Finish: allowed once every step is done or skipped.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function complete(User $actor): Organization
    {
        $organization = $this->ownerOrganization($actor);

        if ($organization->onboarding_completed_at !== null) {
            return $organization;
        }

        if (! $this->state($organization)->isFinished()) {
            throw ValidationException::withMessages(['step' => 'Finish or skip the remaining steps first.']);
        }

        $organization->forceFill(['onboarding_completed_at' => now()])->save();
        OrganizationActivity::record($organization, 'onboarding_completed', $actor);
        Log::info('Onboarding completed.', ['organization_id' => $organization->id]);

        return $organization;
    }

    /**
     * Development/testing only (see the onboarding:reset command): start over.
     */
    public function reset(Organization $organization): void
    {
        $organization->forceFill([
            'business_type' => null, 'onboarding_step' => OnboardingStep::BusinessProfile->value, 'onboarding_progress' => null,
            'onboarding_started_at' => now(), 'onboarding_completed_at' => null, 'onboarding_skipped_at' => null, 'onboarding_checklist_done_at' => null,
        ])->save();
    }

    // ─── Dashboard checklist ─────────────────────────────────────────────────────────────

    /**
     * The "Getting started" list, or null when it shouldn't be shown (everything done, or not the owner).
     *
     * @return list<array{label: string, done: bool, url: string}>|null
     */
    public function checklist(User $user): ?array
    {
        $organization = $user->organization;

        if (! $user->isOwner() || $organization === null || $organization->onboarding_started_at === null || $organization->onboarding_checklist_done_at !== null) {
            return null;
        }

        $state = $this->state($organization);
        $items = [
            ['label' => 'Business profile', 'done' => $state->isDone(OnboardingStep::BusinessProfile), 'url' => route('onboarding.show')],
            ['label' => 'Email connected', 'done' => $state->isDone(OnboardingStep::EmailConnection), 'url' => route('settings.email')],
            ['label' => 'First customer', 'done' => $state->isDone(OnboardingStep::FirstCustomer), 'url' => route('customers.create')],
            ['label' => 'First estimate', 'done' => $state->isDone(OnboardingStep::FirstEstimate), 'url' => route('estimates.create')],
            ['label' => 'Create an automation', 'done' => $state->isDone(OnboardingStep::FirstAutomation), 'url' => route('automations.index')],
            ['label' => 'Invite a teammate', 'done' => $organization->users()->where('status', '!=', 'removed')->count() > 1 || TeamInvitation::query()->where('organization_id', $organization->id)->exists(), 'url' => route('settings.team')],
        ];

        if (collect($items)->every(fn ($i) => $i['done'])) {
            $organization->forceFill(['onboarding_checklist_done_at' => now()])->save();

            return null;
        }

        return $items;
    }

    // ─── Internals ───────────────────────────────────────────────────────────────────────

    /**
     * The draft for a template, re-using one from an earlier try instead of adding a duplicate.
     */
    private function draftOf(Organization $organization, User $actor, string $templateKey): Automation
    {
        $template = AutomationTemplates::all()[$templateKey] ?? abort(404);

        return $organization->automations()->where('name', $template['name'])->where('status', AutomationStatus::Draft)->first()
            ?? $this->templates->install($organization, $actor, $templateKey);
    }

    private function mark(Organization $organization, OnboardingStep $step, string $status): void
    {
        $progress = $organization->onboarding_progress ?? [];
        $progress[$step->value] = $status;
        $organization->forceFill(['onboarding_progress' => $progress])->save();
    }

    /**
     * Only the owner, and only for their own organization.
     *
     * @throws AuthorizationException
     */
    private function ownerOrganization(User $actor): Organization
    {
        if (! $actor->isOwner() || $actor->organization === null) {
            throw new AuthorizationException('Only the business owner can set up the account.');
        }

        return $actor->organization;
    }
}
