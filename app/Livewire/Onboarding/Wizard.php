<?php

namespace App\Livewire\Onboarding;

use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Onboarding\OnboardingStep;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Estimates\EstimateException;
use App\Livewire\Settings\BusinessPreferences;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The first-run wizard (owner only). It shows the step the server says the business is on and calls
 * OnboardingService for every change; nothing is decided in the browser, so leaving and returning
 * resumes where the business really is. The organization is always the signed-in owner's own.
 */
#[Layout('components.layouts.wizard')]
#[Title('Get started')]
class Wizard extends Component
{
    // Business
    public string $businessName = '';

    public string $businessType = '';

    public string $website = '';

    public string $phone = '';

    // Location
    public string $country = 'US';

    public string $stateCode = '';

    public string $city = '';

    public string $timezone = '';

    public bool $timezoneEdited = false;

    // Customer
    public string $customerName = '';

    public string $customerEmail = '';

    public string $customerPhone = '';

    public string $customerCompany = '';

    // Estimate
    public string $estimateCustomerId = '';

    public string $estimateTitle = '';

    public string $estimatePrice = '';

    // Automation
    public string $templateKey = '';

    public bool $confirmed = false;

    /** A failure message for the current step (progress already saved is kept). */
    public ?string $error = null;

    public function mount(OnboardingService $onboarding, BillingService $billing): mixed
    {
        $this->authorize('manage-onboarding');
        $organization = $this->organization();

        // A finished business doesn't see the wizard again.
        if ($organization->onboarding_completed_at !== null) {
            return $this->redirectRoute('dashboard', navigate: true);
        }

        // A subscription that needs fixing (payment never completed) is handled on the billing page first.
        $subscription = $billing->currentSubscription($organization);

        if ($subscription !== null && in_array($subscription->status, [SubscriptionStatus::Incomplete, SubscriptionStatus::Unpaid], true)) {
            return $this->redirectRoute('settings.billing', navigate: true);
        }

        $this->fillFrom($organization);
        $this->templateKey = '';

        return null;
    }

    // ─── Actions ─────────────────────────────────────────────────────────────────────────

    public function start(): void
    {
        $this->guarded(function (OnboardingService $onboarding) {
            $organization = $this->organization();
            $progress = $organization->onboarding_progress ?? [];
            $progress['welcomed'] = true;
            $organization->forceFill(['onboarding_progress' => $progress])->save();
        });
    }

    public function skipAll(): mixed
    {
        $this->guarded(fn (OnboardingService $onboarding) => $onboarding->skipOnboarding($this->user()));

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function saveBusiness(): void
    {
        $this->guarded(fn (OnboardingService $onboarding) => $onboarding->saveBusinessProfile($this->user(), [
            'name' => $this->businessName, 'business_type' => $this->businessType, 'website' => $this->website, 'phone' => $this->phone,
        ]), ['name' => 'businessName', 'business_type' => 'businessType']);
    }

    public function updatedStateCode(OnboardingService $onboarding): void
    {
        $suggestion = $onboarding->suggestedTimezone($this->country, $this->stateCode);

        if ($suggestion !== null && ! $this->timezoneEdited) {
            $this->timezone = $suggestion;
        }
    }

    public function updatedTimezone(): void
    {
        $this->timezoneEdited = true;
    }

    public function saveLocation(): void
    {
        $this->guarded(fn (OnboardingService $onboarding) => $onboarding->saveLocation($this->user(), [
            'country' => $this->country, 'state' => $this->stateCode, 'city' => $this->city, 'timezone' => $this->timezone,
        ]), ['state' => 'stateCode']);
    }

    public function skip(string $step): void
    {
        $this->guarded(fn (OnboardingService $onboarding) => $onboarding->skipStep($this->user(), OnboardingStep::from($step)));
    }

    public function addCustomer(): void
    {
        $this->guarded(function (OnboardingService $onboarding) {
            $onboarding->createFirstCustomer($this->user(), [
                'name' => $this->customerName, 'email' => $this->customerEmail, 'phone' => $this->customerPhone, 'company' => $this->customerCompany,
            ]);
            $this->reset(['customerName', 'customerEmail', 'customerPhone', 'customerCompany']);
        }, ['name' => 'customerName', 'email' => 'customerEmail', 'phone' => 'customerPhone', 'company' => 'customerCompany']);
    }

    public function createSampleCustomer(): void
    {
        $this->guarded(function (OnboardingService $onboarding) {
            $onboarding->createSampleCustomer($this->user());
            $onboarding->skipStep($this->user(), OnboardingStep::FirstCustomer);
        });
    }

    public function removeSampleData(): void
    {
        $this->guarded(fn (OnboardingService $onboarding) => $onboarding->removeSampleData($this->user()));
    }

    public function createEstimate(): void
    {
        $this->guarded(function (OnboardingService $onboarding) {
            $onboarding->createFirstEstimate($this->user(), ['customer_id' => $this->estimateCustomerId, 'title' => $this->estimateTitle, 'unit_price' => $this->estimatePrice]);
            $this->reset(['estimateTitle', 'estimatePrice']);
        }, ['customer_id' => 'estimateCustomerId', 'title' => 'estimateTitle', 'lines' => 'estimatePrice', 'items' => 'estimatePrice']);
    }

    public function chooseTemplate(string $key, OnboardingService $onboarding): void
    {
        abort_unless(array_key_exists($key, $onboarding->suggestedTemplates($this->organization())), 404);
        $this->templateKey = $key;
        $this->confirmed = false;
        $this->error = null;
        $this->resetErrorBag();
    }

    public function backToTemplates(): void
    {
        $this->templateKey = '';
        $this->confirmed = false;
    }

    public function activateAutomation(): void
    {
        $this->guarded(function (OnboardingService $onboarding) {
            $onboarding->activateTemplate($this->user(), $this->templateKey, $this->confirmed);
            $this->templateKey = '';
            $this->confirmed = false;
        }, ['confirmed' => 'confirmed', 'actions' => 'confirmed', 'name' => 'confirmed']);
    }

    public function customizeAutomation(): mixed
    {
        $automation = null;
        $this->guarded(function (OnboardingService $onboarding) use (&$automation) {
            $automation = $onboarding->installTemplate($this->user(), $this->templateKey);
        });

        return $automation === null ? null : $this->redirectRoute('automations.edit', $automation->id, navigate: true);
    }

    public function finish(): mixed
    {
        $done = false;
        $this->guarded(function (OnboardingService $onboarding) use (&$done) {
            $onboarding->complete($this->user());
            $done = true;
        });

        return $done ? $this->redirectRoute('dashboard', navigate: true) : null;
    }

    public function recheckEmail(): void
    {
        $this->error = null;
        $this->organization()->unsetRelation('emailConnections');
    }

    // ─── Rendering ───────────────────────────────────────────────────────────────────────

    public function render(OnboardingService $onboarding, EntitlementService $entitlements)
    {
        $organization = $this->organization()->refresh();
        $state = $onboarding->state($organization);
        $welcomed = (bool) ($organization->onboarding_progress['welcomed'] ?? false) || $state->current !== OnboardingStep::BusinessProfile;
        $step = $state->current;

        return view('livewire.onboarding.wizard', [
            'organization' => $organization,
            'state' => $state,
            'step' => $step,
            'welcomed' => $welcomed,
            'trial' => $entitlements->trial($organization),
            'businessTypes' => collect(config('onboarding.business_types'))->map(fn ($t) => $t['label'])->all(),
            'emailConnection' => $organization->emailConnections()->default()->verified()->first(),
            'customers' => $step === OnboardingStep::FirstEstimate ? $organization->customers()->orderBy('is_demo')->latest('id')->limit(50)->get() : collect(),
            'sample' => $organization->customers()->where('is_demo', true)->first(),
            'estimateDefaults' => $step === OnboardingStep::FirstEstimate ? $onboarding->estimateDefaults($organization) : [],
            'templates' => $step === OnboardingStep::FirstAutomation ? $onboarding->suggestedTemplates($organization) : [],
            'review' => $step === OnboardingStep::FirstAutomation && $this->templateKey !== '' ? $onboarding->review($this->user(), $this->templateKey) : null,
            'summary' => $step === OnboardingStep::Completed ? [
                'customers' => $organization->customers()->where('is_demo', false)->count(),
                'estimates' => $organization->estimates()->count(),
                'automations' => $organization->automations()->where('status', 'active')->count(),
                'follow_ups' => $organization->followUps()->count(),
            ] : [],
            'countries' => BusinessSettingsService::COUNTRIES,
            'timezones' => BusinessPreferences::COMMON_TIMEZONES,
        ]);
    }

    // ─── Internals ───────────────────────────────────────────────────────────────────────

    private function user(): User
    {
        return Auth::user();
    }

    private function organization(): Organization
    {
        return $this->user()->organization ?? abort(403);
    }

    private function fillFrom(Organization $organization): void
    {
        $this->businessName = $organization->name;
        $this->businessType = (string) $organization->business_type;
        $this->website = (string) $organization->website;
        $this->phone = (string) $organization->phone;
        $this->country = (string) ($organization->country ?: 'US');
        $this->stateCode = (string) $organization->state;
        $this->city = (string) $organization->city;
        $this->timezone = $organization->timezone();
    }

    /**
     * Run a step action: validation errors go to the matching field, anything else to a
     * message on the step. Progress already saved is never touched.
     *
     * @param  array<string, string>  $fields  service field => component property
     */
    private function guarded(\Closure $action, array $fields = []): void
    {
        $this->error = null;
        $this->resetErrorBag();

        try {
            $action(app(OnboardingService::class));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($fields[$field] ?? $field, $messages[0]);
            }
        } catch (PlanLimitException|EstimateException $e) {
            $this->error = $e->getMessage();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Something went wrong. Your progress is saved — please try again.';
        }
    }
}
