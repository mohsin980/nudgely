<?php

use App\Enums\TaskPriority;
use App\Livewire\Estimates\EstimateForm;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Settings\BusinessPreferences;
use App\Livewire\Settings\BusinessProfile;
use App\Livewire\Settings\EstimateDefaults;
use App\Livewire\Settings\FollowUpDefaults;
use App\Models\Estimate;
use App\Models\OrganizationActivity;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function onMonday(): void
{
    test()->travelTo(now()->setTimezone('America/Chicago')->setDate(2026, 10, 5)->setTime(9, 0));
}

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness();
});

// Business settings

test('1. the business profile can be viewed by the owner', function () {
    $this->actingAs($this->owner)->get('/settings/business')->assertOk()
        ->assertSee('Business Profile')->assertSee('Dallas HVAC')->assertSee('Upload your business logo.')
        ->assertSee('hello@dallashvac.com')->assertSee('Verified')->assertSee('Manage Email');
    $this->actingAs($this->owner)->get('/settings')->assertRedirect(route('settings.business'));
});

test('2. the owner can update the business profile and the change is audited', function () {
    Livewire::actingAs($this->owner)->test(BusinessProfile::class)
        ->set('email', 'Hello@DallasHVAC.com')->set('phone', '(214) 555-1234')->set('website', 'https://dallashvac.com')
        ->set('addressLine1', '100 Main St')->set('city', 'Dallas')->set('state', 'TX')->set('postalCode', '75201')
        ->call('save')->assertHasNoErrors()->assertSee('Business profile saved.')->assertDispatched('settings-saved');

    $organization = $this->organization->fresh();
    expect($organization->email)->toBe('hello@dallashvac.com')
        ->and($organization->phone)->toBe('(214) 555-1234')
        ->and($organization->postal_code)->toBe('75201');

    $entry = OrganizationActivity::where('action', 'business_profile_updated')->sole();
    expect($entry->user_id)->toBe($this->owner->id)
        ->and($entry->data['changes']['Phone'])->toEqual(['from' => null, 'to' => '(214) 555-1234']);

    // Only the name is required; bad values are rejected with clear messages.
    Livewire::actingAs($this->owner)->test(BusinessProfile::class)
        ->set('name', '')->set('email', 'not-an-email')->set('phone', 'call me')->set('website', 'dallashvac')->set('postalCode', '7520')
        ->call('save')->assertHasErrors(['name', 'email', 'phone', 'website', 'postalCode']);
    expect($this->organization->fresh()->name)->toBe('Dallas HVAC');
});

test('3. managers and staff cannot update the business profile', function () {
    foreach ([$this->manager, $this->staff] as $user) {
        $this->actingAs($user)->get('/settings/business')->assertForbidden();
        expect(fn () => app(BusinessSettingsService::class)->updateProfile($user, ['name' => 'Hacked', 'country' => 'US']))->toThrow(AuthorizationException::class);
    }

    expect($this->organization->fresh()->name)->toBe('Dallas HVAC');
});

test('4. a logo can be uploaded, replaced and is served as an image', function () {
    Storage::fake('local');

    Livewire::actingAs($this->owner)->test(BusinessProfile::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 300, 120))->call('uploadLogo')->assertHasNoErrors()->assertSee('Logo uploaded.');

    $first = $this->organization->fresh()->logo_path;
    expect($first)->toMatch('#^logos/[a-z0-9]{40}\.png$#');
    Storage::disk('local')->assertExists($first);

    $this->get(route('logos.show', basename($first)))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    // Replacing deletes the previous file; the old URL stops working.
    Livewire::actingAs($this->owner)->test(BusinessProfile::class)
        ->set('logo', UploadedFile::fake()->image('new.jpg', 200, 200))->call('uploadLogo')->assertHasNoErrors();
    $second = $this->organization->fresh()->logo_path;
    expect($second)->toEndWith('.jpg')->not->toBe($first);
    Storage::disk('local')->assertMissing($first);
    $this->get(route('logos.show', basename($first)))->assertNotFound();
});

test('5. invalid logos are rejected', function () {
    Storage::fake('local');
    $bad = [
        UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";'),
        UploadedFile::fake()->image('huge.png', 400, 400)->size(3000),
    ];

    foreach ($bad as $file) {
        Livewire::actingAs($this->owner)->test(BusinessProfile::class)->set('logo', $file)->call('uploadLogo')->assertHasErrors('logo');
    }

    expect($this->organization->fresh()->logo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('logos'))->toBe([]);
});

test('6. the timezone must be a real IANA timezone', function () {
    Livewire::actingAs($this->owner)->test(BusinessPreferences::class)
        ->assertSet('timezone', 'America/Chicago')
        ->set('timezone', 'Central Time')->call('save')->assertHasErrors('timezone')
        ->set('timezone', 'America/New_York')->call('save')->assertHasNoErrors();

    expect($this->organization->fresh()->timezone)->toBe('America/New_York')
        ->and(OrganizationActivity::where('action', 'preferences_updated')->sole()->data['changes']['Timezone'])->toEqual(['from' => 'America/Chicago', 'to' => 'America/New_York']);
});

test('7. the currency must be supported and is used for new estimates', function () {
    onMonday();
    Livewire::actingAs($this->owner)->test(BusinessPreferences::class)
        ->set('currency', 'EUR')->call('save')->assertHasErrors('currency')
        ->set('currency', 'CAD')->set('dateFormat', 'm/d/Y')->set('timeFormat', '24h')->set('taskPriority', 'high')->call('save')->assertHasNoErrors();

    $organization = $this->organization->fresh();
    expect($organization->currencyCode())->toBe('CAD')
        ->and($organization->businessSettings()->taskPriority())->toBe(TaskPriority::High)
        ->and($organization->formatDateTime(now()))->toBe('10/05/2026 09:00');

    expect(draftEstimate($this->owner->fresh(), $this->customer)->currency)->toBe('CAD');
});

test('business hours are stored per day and validated', function () {
    onMonday();
    Livewire::actingAs($this->owner)->test(BusinessPreferences::class)
        ->set('hours.sat.open', true)->set('hours.sat.start', '12:00')->set('hours.sat.end', '09:00')->call('saveHours')->assertHasErrors('hours.sat')
        ->set('hours.sat.end', '14:00')->call('saveHours')->assertHasNoErrors();

    $hours = $this->organization->fresh()->businessSettings()->businessHours();
    expect($hours->toArray()['sat'])->toBe(['open' => true, 'start' => '12:00', 'end' => '14:00'])
        // Monday 6 PM Central is after closing: the next opening is Tuesday 8 AM (extension point for automations).
        ->and($hours->nextOpening(now()->setTimezone('America/Chicago')->setTime(18, 0), 'America/Chicago')->format('D H:i'))->toBe('Tue 08:00');
});

// Defaults

test('41. estimate defaults pre-fill a new estimate', function () {
    onMonday();
    Livewire::actingAs($this->manager)->test(EstimateDefaults::class)
        ->set('validDays', '45')->set('notes', 'Thank you for considering our services.')->set('taxRate', '8.25')
        ->call('save')->assertHasNoErrors();

    Livewire::actingAs($this->staff)->test(EstimateForm::class)
        ->assertSet('validUntil', '2026-11-19')
        ->assertSet('notes', 'Thank you for considering our services.')
        ->assertSet('taxRate', '8.25');

    expect(OrganizationActivity::where('action', 'estimate_defaults_updated')->sole()->data['changes']['Default estimate validity (days)'])->toEqual(['from' => 30, 'to' => 45]);
});

test('42. follow-up defaults pre-fill new follow-ups', function () {
    onMonday();
    Livewire::actingAs($this->owner)->test(FollowUpDefaults::class)
        ->set('delayDays', '0')->call('save')->assertHasErrors('delayDays')
        ->set('delayDays', '5')->set('time', '08:30')->call('save')->assertHasNoErrors();

    Livewire::actingAs($this->staff)->test(FollowUpIndex::class)
        ->call('openScheduleForm')
        ->assertSet('scheduleDate', '2026-10-10')
        ->assertSet('scheduleTime', '08:30');
});

test('43. explicit values override the defaults', function () {
    app(BusinessSettingsService::class)->updateEstimateDefaults($this->owner, ['valid_days' => 45, 'notes' => 'Default note', 'tax_rate' => '8.25']);

    Livewire::withQueryParams(['customer' => $this->customer->id])->actingAs($this->owner)->test(EstimateForm::class)
        ->set('title', 'Furnace')->set('items.0.description', 'Furnace')->set('items.0.unit_price', '1000')
        ->set('validUntil', '2026-12-04')->set('notes', 'Custom note')->set('taxRate', '0')
        ->call('save')->assertHasNoErrors();

    $estimate = Estimate::sole();
    expect($estimate->valid_until->toDateString())->toBe('2026-12-04')
        ->and($estimate->notes)->toBe('Custom note')
        ->and((float) $estimate->tax_rate)->toBe(0.0);
});

test('managers can change business defaults but staff cannot', function () {
    $this->actingAs($this->manager)->get('/settings/estimates')->assertOk();
    $this->actingAs($this->manager)->get('/settings/follow-ups')->assertOk();
    $this->actingAs($this->manager)->get('/settings/automation')->assertOk();
    $this->actingAs($this->manager)->get('/settings/preferences')->assertForbidden();

    foreach (['/settings/estimates', '/settings/follow-ups', '/settings/automation'] as $url) {
        $this->actingAs($this->staff)->get($url)->assertForbidden();
    }
});
