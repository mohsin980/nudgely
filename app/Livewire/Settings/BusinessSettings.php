<?php

namespace App\Livewire\Settings;

use App\Models\Automation;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business-wide settings. For now: the timezone follow-up times are shown and entered in.
 */
#[Layout('components.layouts.app')]
#[Title('Business Settings')]
class BusinessSettings extends Component
{
    /**
     * The common US zones, offered first. Any US IANA zone is accepted.
     */
    public const COMMON_TIMEZONES = [
        'America/New_York' => 'Eastern',
        'America/Chicago' => 'Central',
        'America/Denver' => 'Mountain',
        'America/Phoenix' => 'Arizona',
        'America/Los_Angeles' => 'Pacific',
        'America/Anchorage' => 'Alaska',
        'Pacific/Honolulu' => 'Hawaii',
    ];

    public string $timezone = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        // Same audience as automation settings: organization admins.
        $this->authorize('create', Automation::class);
        $this->timezone = Auth::user()->organization->timezone();
    }

    public function save(): void
    {
        $this->authorize('create', Automation::class);
        $this->resetErrorBag();
        $this->statusMessage = null;

        if (! in_array($this->timezone, DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US'), true)) {
            $this->addError('timezone', 'Choose a US timezone.');

            return;
        }

        $organization = Auth::user()->organization;
        $organization->forceFill(['timezone' => $this->timezone])->save();
        Log::info('Organization timezone changed.', ['organization_id' => $organization->id, 'timezone' => $this->timezone, 'user_id' => Auth::id()]);

        $this->statusMessage = 'Business settings saved.';
    }

    public function render()
    {
        return view('livewire.settings.business-settings', ['timezones' => self::COMMON_TIMEZONES]);
    }
}
