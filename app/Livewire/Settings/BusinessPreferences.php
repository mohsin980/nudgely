<?php

namespace App\Livewire\Settings;

use App\Enums\TaskPriority;
use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\Organization;
use App\Services\Settings\BusinessSettingsService;
use App\Support\Settings\OrganizationSettings;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Timezone, currency, date/time format, default task priority and business hours (owner).
 */
#[Layout('components.layouts.app')]
#[Title('Business Preferences')]
class BusinessPreferences extends Component
{
    use SettingsPage;

    /** Offered first; any IANA timezone is accepted. */
    public const COMMON_TIMEZONES = [
        'America/New_York' => 'Eastern', 'America/Chicago' => 'Central', 'America/Denver' => 'Mountain', 'America/Phoenix' => 'Arizona',
        'America/Los_Angeles' => 'Pacific', 'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii',
    ];

    public string $timezone = '';

    public string $currency = 'USD';

    public string $dateFormat = 'M j, Y';

    public string $timeFormat = '12h';

    public string $taskPriority = 'medium';

    /** @var array<string, array{open: bool, start: string, end: string}> */
    public array $hours = [];

    public function mount(): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);
        $organization = $this->organization();
        $this->timezone = $organization->timezone();
        $this->currency = $organization->currencyCode();
        $this->dateFormat = $organization->dateFormat();
        $this->timeFormat = $organization->time_format === '24h' ? '24h' : '12h';
        $this->taskPriority = $organization->businessSettings()->taskPriority()->value;
        $this->hours = $organization->businessSettings()->businessHours()->toArray();
    }

    public function save(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);

        try {
            $settings->updatePreferences($this->user(), [
                'timezone' => $this->timezone, 'currency' => $this->currency, 'date_format' => $this->dateFormat,
                'time_format' => $this->timeFormat, 'task_priority' => $this->taskPriority,
            ]);
        } catch (ValidationException $e) {
            $this->showErrors($e, ['date_format' => 'dateFormat', 'time_format' => 'timeFormat', 'task_priority' => 'taskPriority']);

            return;
        }

        $this->saved('Preferences saved.');
    }

    public function saveHours(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);

        try {
            $settings->updateBusinessHours($this->user(), $this->hours);
        } catch (ValidationException $e) {
            $this->showErrors($e);

            return;
        }

        $this->saved('Business hours saved.');
    }

    public function render()
    {
        return view('livewire.settings.business-preferences', [
            'organization' => $this->organization(),
            'commonTimezones' => self::COMMON_TIMEZONES,
            'allTimezones' => DateTimeZone::listIdentifiers(),
            'currencies' => Organization::CURRENCIES,
            'dateFormats' => Organization::DATE_FORMATS,
            'timeFormats' => Organization::TIME_FORMATS,
            'priorities' => TaskPriority::cases(),
            'days' => OrganizationSettings::DAYS,
            'history' => $this->history(['preferences_updated', 'business_hours_updated']),
        ]);
    }
}
