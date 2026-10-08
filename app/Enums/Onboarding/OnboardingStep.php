<?php

namespace App\Enums\Onboarding;

/**
 * The onboarding state machine, in order. A step is "done" when the real thing exists (a verified
 * email, a customer, ...) or, for steps without a record to point at, when it was saved; it can be
 * "skipped" if it is optional. The current step is the first one that is neither.
 */
enum OnboardingStep: string
{
    case BusinessProfile = 'business_profile';
    case BusinessPreferences = 'business_preferences';
    case EmailConnection = 'email_connection';
    case FirstCustomer = 'first_customer';
    case FirstEstimate = 'first_estimate';
    case FirstAutomation = 'first_automation';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::BusinessProfile => 'Business details',
            self::BusinessPreferences => 'Location & timezone',
            self::EmailConnection => 'Email',
            self::FirstCustomer => 'Customer',
            self::FirstEstimate => 'Estimate',
            self::FirstAutomation => 'Automation',
            self::Completed => 'Ready',
        };
    }

    /**
     * The five headings shown in the progress list (location is part of "Business").
     */
    public function group(): string
    {
        return match ($this) {
            self::BusinessProfile, self::BusinessPreferences => 'Business',
            self::EmailConnection => 'Email',
            self::FirstCustomer => 'Customer',
            self::FirstEstimate => 'Estimate',
            self::FirstAutomation => 'Automation',
            self::Completed => 'Ready',
        };
    }

    /**
     * Only the business identity is required; everything else can be skipped.
     */
    public function isRequired(): bool
    {
        return $this === self::BusinessProfile;
    }

    /**
     * @return list<self> the steps that can be done or skipped (all but "completed")
     */
    public static function working(): array
    {
        return array_values(array_filter(self::cases(), fn (self $step) => $step !== self::Completed));
    }
}
