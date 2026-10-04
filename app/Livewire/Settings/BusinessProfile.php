<?php

namespace App\Livewire\Settings;

use App\Enums\Team\Permission;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Business name, contact details, address and logo (owner).
 */
#[Layout('components.layouts.app')]
#[Title('Business Profile')]
class BusinessProfile extends Component
{
    use SettingsPage;
    use WithFileUploads;

    private const FIELDS = [
        'name' => 'name', 'legal_name' => 'legalName', 'email' => 'email', 'phone' => 'phone', 'website' => 'website',
        'address_line1' => 'addressLine1', 'address_line2' => 'addressLine2', 'city' => 'city', 'state' => 'state', 'postal_code' => 'postalCode', 'country' => 'country',
    ];

    public string $name = '';

    public string $legalName = '';

    public string $email = '';

    public string $phone = '';

    public string $website = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $state = '';

    public string $postalCode = '';

    public string $country = 'US';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public function mount(): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);
        $organization = $this->organization();

        foreach (self::FIELDS as $column => $property) {
            $this->{$property} = (string) ($organization->{$column} ?? '');
        }
    }

    public function save(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);

        try {
            $settings->updateProfile($this->user(), collect(self::FIELDS)->mapWithKeys(fn ($property, $column) => [$column => $this->{$property}])->all());
        } catch (ValidationException $e) {
            $this->showErrors($e, self::FIELDS);

            return;
        }

        $this->saved('Business profile saved.');
    }

    public function uploadLogo(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);

        if (! $this->logo instanceof TemporaryUploadedFile) {
            $this->addError('logo', 'Choose an image to upload.');

            return;
        }

        try {
            $settings->uploadLogo($this->user(), $this->logo);
        } catch (ValidationException $e) {
            $this->showErrors($e);

            return;
        } finally {
            $this->logo?->delete();
            $this->logo = null;
        }

        $this->saved('Logo uploaded.');
    }

    public function removeLogo(BusinessSettingsService $settings): void
    {
        $this->authorize(Permission::ManageBusinessProfile->value);
        $settings->removeLogo($this->user());
        $this->saved('Logo removed.');
    }

    public function render()
    {
        if (session()->has('settings-status')) {
            $this->statusMessage = session('settings-status');
        }

        $organization = $this->organization()->refresh();

        return view('livewire.settings.business-profile', [
            'organization' => $organization,
            'countries' => BusinessSettingsService::COUNTRIES,
            'sender' => $organization->emailConnections()->where('is_default', true)->first(),
            'history' => $this->history(['business_profile_updated', 'logo_updated', 'logo_removed']),
        ]);
    }
}
