<?php

use App\Livewire\Onboarding\Wizard;
use App\Livewire\Settings\BusinessProfile;
use App\Models\Customer;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\BusinessSettingsService;
use App\Support\Countries;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function lahoreOwner(): User
{
    $owner = User::factory()->owner()->create(['name' => 'Mohsin Ali']);
    $owner->organization->forceFill(['name' => 'Lahore Estates', 'timezone' => 'America/Chicago'])->save();
    app(OnboardingService::class)->begin($owner->organization);

    return $owner->fresh();
}

test('businesses outside North America can choose their country', function () {
    expect(Countries::names())->toHaveKeys(['US', 'CA', 'PK', 'IN', 'AE', 'SA', 'GB', 'AU'])
        ->and(Countries::names()['PK'])->toBe('Pakistan');
});

test('during onboarding, a Pakistani business gets Pakistan time and rupees', function () {
    $owner = lahoreOwner();
    app(OnboardingService::class)->saveBusinessProfile($owner, ['name' => 'Lahore Estates', 'business_type' => 'other', 'phone' => '0300 1234567']);

    Livewire::actingAs($owner->fresh())->test(Wizard::class)
        ->assertSee('Where are you located?')
        ->set('country', 'PK')
        ->assertSet('timezone', 'Asia/Karachi')
        ->assertSee('Province')->assertSee('Suggested from your country');

    app(OnboardingService::class)->saveLocation($owner, ['country' => 'PK', 'state' => 'Punjab', 'city' => 'Lahore', 'timezone' => 'Asia/Karachi']);

    expect($owner->organization->fresh())
        ->country->toBe('PK')->state->toBe('Punjab')->timezone->toBe('Asia/Karachi')->currency->toBe('PKR');
    expect(Money::format(15000000, 'PKR'))->toBe('PKR 150,000.00');
});

test('a currency the business already chose is kept', function () {
    $owner = lahoreOwner();
    $owner->organization->forceFill(['currency' => 'CAD'])->save();

    app(OnboardingService::class)->saveLocation($owner, ['country' => 'PK', 'timezone' => 'Asia/Karachi']);

    expect($owner->organization->fresh()->currency)->toBe('CAD');
});

test('local phone numbers use the business country\'s code, as WhatsApp needs', function () {
    expect(PhoneNumber::toE164('0300 1234567', 'PK'))->toBe('+923001234567')
        ->and(PhoneNumber::toE164('050 123 4567', 'AE'))->toBe('+971501234567')
        ->and(PhoneNumber::toE164('07700 900123', 'GB'))->toBe('+447700900123')
        ->and(PhoneNumber::toE164('(214) 555-0142', 'US'))->toBe('+12145550142')
        ->and(PhoneNumber::toE164('+1 214 555 0142', 'PK'))->toBe('+12145550142'); // written with its own code

    $owner = lahoreOwner();
    $owner->organization->forceFill(['country' => 'PK'])->save();
    $customer = Customer::factory()->for($owner->organization)->create(['phone' => '0321-7654321']);

    expect($customer->fresh()->phone_e164)->toBe('+923217654321');
});

test('postal codes are checked the way each country writes them', function () {
    $owner = lahoreOwner();
    $save = fn (string $country, string $postal) => app(BusinessSettingsService::class)->updateProfile($owner, ['name' => 'Lahore Estates', 'country' => $country, 'postal_code' => $postal]);

    expect($save('PK', '54000')->postal_code)->toBe('54000')
        ->and($save('GB', 'SW1A 1AA')->postal_code)->toBe('SW1A 1AA')
        ->and($save('AE', 'anything')->country)->toBe('AE') // not checked where there is no standard format
        ->and(fn () => $save('PK', 'ABC'))->toThrow(ValidationException::class, 'Enter a postal code like 54000.')
        ->and(fn () => $save('XX', ''))->toThrow(ValidationException::class);
});

test('the business profile page lists the countries and names the region the local way', function () {
    $owner = lahoreOwner();
    $owner->organization->forceFill(['country' => 'AE'])->save();

    Livewire::actingAs($owner)->test(BusinessProfile::class)
        ->assertSee('United Arab Emirates')->assertSee('Emirate')->assertSee('Postal code')
        ->set('country', 'US')->assertSee('ZIP code');
});
