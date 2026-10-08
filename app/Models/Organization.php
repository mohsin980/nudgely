<?php

namespace App\Models;

use App\Support\Settings\OrganizationSettings;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'automations_enabled' => true,
        'automatic_email_enabled' => false,
        'require_approval_for_email' => true,
        'country' => 'US',
        'currency' => 'USD',
        'date_format' => 'M j, Y',
        'time_format' => '12h',
    ];

    /** Supported currencies (no conversion): amounts are shown in this currency on new estimates. */
    public const CURRENCIES = ['USD' => 'US dollar (USD)', 'CAD' => 'Canadian dollar (CAD)'];

    /** PHP date formats offered to businesses. */
    public const DATE_FORMATS = ['M j, Y' => 'Mon D, YYYY (Oct 4, 2026)', 'm/d/Y' => 'MM/DD/YYYY (10/04/2026)'];

    public const TIME_FORMATS = ['12h' => '12-hour (2:30 PM)', '24h' => '24-hour (14:30)'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'automations_enabled' => 'boolean',
            'automatic_email_enabled' => 'boolean',
            'require_approval_for_email' => 'boolean',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'trial_used_at' => 'datetime',
            'trial_expired_at' => 'datetime',
            'onboarding_progress' => 'array',
            'onboarding_started_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'onboarding_skipped_at' => 'datetime',
            'onboarding_checklist_done_at' => 'datetime',
        ];
    }

    /**
     * Business defaults with built-in fallbacks (see OrganizationSettings).
     */
    public function businessSettings(): OrganizationSettings
    {
        return OrganizationSettings::fromArray($this->settings);
    }

    /**
     * The one place dates are formatted with the business's preferences (timezone and format).
     */
    public function formatDate(?\DateTimeInterface $time): string
    {
        return $time === null ? '' : $this->localTime($time)->format($this->dateFormat());
    }

    public function formatTime(?\DateTimeInterface $time): string
    {
        return $time === null ? '' : $this->localTime($time)->format($this->time_format === '24h' ? 'H:i' : 'g:i A');
    }

    public function formatDateTime(?\DateTimeInterface $time): string
    {
        return $time === null ? '' : $this->formatDate($time).' '.$this->formatTime($time);
    }

    /**
     * A calendar date (e.g. an estimate's valid-until date) without timezone conversion.
     */
    public function formatCalendarDate(?\DateTimeInterface $date): string
    {
        return $date === null ? '' : CarbonImmutable::instance($date)->format($this->dateFormat());
    }

    public function dateFormat(): string
    {
        return array_key_exists((string) $this->date_format, self::DATE_FORMATS) ? $this->date_format : 'M j, Y';
    }

    public function currencyCode(): string
    {
        return array_key_exists((string) $this->currency, self::CURRENCIES) ? $this->currency : 'USD';
    }

    /**
     * Public URL of the uploaded logo (served by LogoController), or null.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : route('logos.show', basename($this->logo_path));
    }

    /**
     * Automations may send email to customers without a person approving each one.
     */
    public function allowsUnattendedAutomatedEmail(): bool
    {
        return $this->automations_enabled && $this->automatic_email_enabled && ! $this->require_approval_for_email;
    }

    /**
     * The organization's timezone, or the documented default (config follow_ups.default_timezone).
     */
    public function timezone(): string
    {
        return $this->timezone ?: config('follow_ups.default_timezone');
    }

    /**
     * A stored (UTC) time in the organization's timezone, for display.
     */
    public function localTime(\DateTimeInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone($this->timezone());
    }

    /**
     * "Now" in the organization's timezone, e.g. to find today's start and end.
     */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * Every subscription the organization has had (newest last).
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The one subscription that isn't cancelled or expired, if any.
     *
     * @return HasOne<Subscription, $this>
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->current()->latestOfMany();
    }

    /**
     * @return HasMany<EmailConnection, $this>
     */
    public function emailConnections(): HasMany
    {
        return $this->hasMany(EmailConnection::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Automation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<CustomerTag, $this>
     */
    public function customerTags(): HasMany
    {
        return $this->hasMany(CustomerTag::class);
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * @return HasMany<Estimate, $this>
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }
}
