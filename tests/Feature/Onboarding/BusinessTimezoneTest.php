<?php

use App\Livewire\Onboarding\Wizard;
use App\Livewire\Settings\BusinessPreferences;
use App\Livewire\Settings\BusinessProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\BusinessSettingsService;
use App\Support\Timezones;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function signUpFrom(?string $timezone): Organization
{
    Notification::fake();
    test()->post('/register', array_filter([
        'business_name' => 'Lahore Estates', 'name' => 'Mohsin Ali', 'email' => 'mohsin@lahore.example',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery', 'timezone' => $timezone,
    ]))->assertRedirect(route('verification.notice'));

    return User::query()->where('email', 'mohsin@lahore.example')->sole()->organization;
}

test('a new business starts in the owner\'s own timezone and country', function () {
    expect(signUpFrom('Asia/Karachi'))->timezone->toBe('Asia/Karachi')->country->toBe('PK');
});

test('a sign-up without a usable device timezone falls back to the default', function () {
    expect(signUpFrom('Mars/Olympus_Mons'))->timezone->toBe(config('follow_ups.default_timezone'));
});

test('moving the business to another country moves its timezone there, and says so', function () {
    $owner = User::factory()->owner()->create();
    $owner->organization->forceFill(['country' => 'US', 'timezone' => 'America/Chicago'])->save();

    Livewire::actingAs($owner)->test(BusinessProfile::class)
        ->set('country', 'PK')->call('save')
        ->assertSee('The timezone is now Asia/Karachi to match Pakistan');

    expect($owner->organization->fresh()->timezone)->toBe('Asia/Karachi');
});

test('a timezone the owner chose that already fits the new country is kept', function () {
    $owner = User::factory()->owner()->create();
    $owner->organization->forceFill(['country' => 'PK', 'timezone' => 'America/Denver'])->save();

    // Back to the US: Denver is a US zone, so it stays.
    app(BusinessSettingsService::class)->updateProfile($owner, ['name' => 'Acme', 'country' => 'US']);
    expect($owner->organization->fresh()->timezone)->toBe('America/Denver');

    // Saving the profile without changing the country never touches the timezone.
    $owner->organization->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    app(BusinessSettingsService::class)->updateProfile($owner->fresh(), ['name' => 'Acme 2', 'country' => 'US']);
    expect($owner->organization->fresh()->timezone)->toBe('Asia/Tokyo');
});

test('the owner can pick any timezone; the business\'s country is suggested first, with offsets', function () {
    $owner = User::factory()->owner()->create();
    $owner->organization->forceFill(['country' => 'PK', 'timezone' => 'Asia/Karachi'])->save();

    $groups = Timezones::grouped('PK');
    expect(array_key_first($groups))->toBe('Pakistan')
        ->and($groups['Pakistan'])->toHaveKey('Asia/Karachi')
        ->and($groups['Popular'])->toHaveKey('America/New_York')->not->toHaveKey('Asia/Karachi')
        ->and($groups['All timezones'])->toHaveKey('Asia/Tokyo')
        ->and(Timezones::label('Asia/Karachi'))->toBe('(UTC+05:00) Karachi');

    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));

    Livewire::actingAs($owner)->test(BusinessPreferences::class)
        ->assertSee('(UTC+05:00) Karachi')->assertSee('5:00 PM, Sun') // the picked zone's local time
        ->set('timezone', 'America/Chicago')->assertSee('6:00 AM, Sun')
        ->set('timezone', 'Asia/Karachi')->assertDontSee('This timezone isn')
        ->set('timezone', 'America/Chicago')->assertSee("This timezone isn't in Pakistan", false)
        ->call('save');

    expect($owner->organization->fresh()->timezone)->toBe('America/Chicago');
});

test('onboarding lists the country\'s zones first and every other timezone', function () {
    $owner = User::factory()->owner()->create();
    $owner->organization->forceFill(['country' => 'AE', 'timezone' => 'Asia/Dubai'])->save();
    app(OnboardingService::class)->begin($owner->organization);
    app(OnboardingService::class)->saveBusinessProfile($owner, ['name' => 'Dubai Homes', 'business_type' => 'other', 'phone' => '050 123 4567']);

    Livewire::actingAs($owner->fresh())->test(Wizard::class)
        ->assertSee('Where are you located?')
        ->assertSeeHtml('<optgroup label="United Arab Emirates">')->assertSee('(UTC+04:00) Dubai')->assertSee('Tokyo');
});
