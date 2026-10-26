<?php

use App\Enums\EmailProvider;
use App\Enums\Onboarding\OnboardingStep;
use App\Livewire\Onboarding\Wizard;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

/** An owner whose business profile and location are saved, so the email step is the next one. */
function navOwnerAtEmailStep(): array
{
    $owner = User::factory()->owner()->create(['name' => 'John Smith']);
    $organization = $owner->organization;
    $organization->forceFill(['name' => 'Dallas HVAC', 'timezone' => 'America/Chicago', 'automations_enabled' => true])->save();

    $onboarding = app(OnboardingService::class);
    $onboarding->begin($organization);
    $onboarding->saveBusinessProfile($owner, ['name' => 'Dallas HVAC', 'business_type' => 'hvac', 'phone' => '(214) 555-1234', 'website' => 'dallashvac.com']);
    $onboarding->saveLocation($owner, ['country' => 'US', 'state' => 'TX', 'city' => 'Dallas', 'timezone' => 'America/Chicago']);

    return [$owner->fresh(), $organization->fresh()];
}

function navConnect(Organization $organization, array $attributes = []): EmailConnection
{
    return EmailConnection::factory()->verified()->default()->create(array_merge([
        'organization_id' => $organization->id,
        'provider' => EmailProvider::Postmark,
        'domain' => 'dallashvac.com',
        'sender_email' => 'sales@dallashvac.com',
        'sender_name' => 'Dallas HVAC',
    ], $attributes));
}

// Back navigation

test('going back shows the previous step without changing saved progress', function () {
    [$owner, $organization] = navOwnerAtEmailStep();
    $before = app(OnboardingService::class)->state($organization)->percent();

    Livewire::actingAs($owner)->test(Wizard::class)
        ->assertSee('Connect your business email')->assertSee('Step 3 of 6')
        ->call('goBack')
        ->assertSee('Where are you located?')->assertSee('Step 2 of 6')->assertSee('Dallas')
        ->assertSee('Continue');

    $state = app(OnboardingService::class)->state($organization->fresh());
    expect($state->current)->toBe(OnboardingStep::EmailConnection)
        ->and($state->isDone(OnboardingStep::BusinessProfile))->toBeTrue()
        ->and($state->isDone(OnboardingStep::BusinessPreferences))->toBeTrue()
        ->and($state->percent())->toBe($before);
});

test('going back from the first step does nothing', function () {
    $owner = User::factory()->owner()->create();
    app(OnboardingService::class)->begin($owner->organization);

    Livewire::actingAs($owner->fresh())->test(Wizard::class)
        ->set('viewing', null)
        ->call('start')
        ->assertSee('About your business')->assertDontSee('data-back')
        ->call('goBack')
        ->assertSee('About your business')->assertSee('Step 1 of 6');
});

test('a forged request cannot show a step that is not yet reached', function () {
    [$owner] = navOwnerAtEmailStep();

    Livewire::actingAs($owner)->test(Wizard::class)
        ->set('viewing', OnboardingStep::FirstAutomation->value)
        ->assertSee('Connect your business email')->assertDontSee('Choose a follow-up to start with');
});

test('saving the step shown after going back returns to the step that is really current', function () {
    [$owner, $organization] = navOwnerAtEmailStep();

    Livewire::actingAs($owner)->test(Wizard::class)
        ->call('goBack')
        ->call('saveLocation')
        ->assertSee('Connect your business email')->assertSee('Step 3 of 6');

    expect(app(OnboardingService::class)->state($organization->fresh())->current)->toBe(OnboardingStep::EmailConnection);
});

// Email step status

test('the email step names the connected sender, but connected steps move on automatically', function () {
    [$owner, $organization] = navOwnerAtEmailStep();
    navConnect($organization, ['provider' => EmailProvider::Gmail]);

    // Connected: the step is done, so it is not shown on its own. Going back shows its real status.
    Livewire::actingAs($owner)->test(Wizard::class)->assertSee('Add a customer to try QuoteFollow')
        ->call('goBack')
        ->assertSee('Connected: ')->assertSee('sales@dallashvac.com')->assertSee('via Gmail')
        ->assertSee('Send a test email to me');
});

test('a connection that needs reconnecting says so, and cannot send a test email', function () {
    [$owner, $organization] = navOwnerAtEmailStep();
    navConnect($organization, ['connection_status' => EmailConnection::STATUS_REAUTH_REQUIRED]);

    Livewire::actingAs($owner)->test(Wizard::class)
        ->assertSee('Needs reconnecting')->assertSee('sales@dallashvac.com')
        ->assertDontSee('Send a test email to me');
});

test('an unverified connection is not shown as connected', function () {
    [$owner, $organization] = navOwnerAtEmailStep();
    navConnect($organization, ['verification_status' => 'pending', 'verified_at' => null]);

    Livewire::actingAs($owner)->test(Wizard::class)
        ->assertSee('Not verified yet')->assertDontSee('Connected:')
        ->assertSee('Without a working email account, follow-ups and estimates')
        ->assertDontSee('Send a test email to me');
});

test('with no connection, the email step says not connected and warns what that means', function () {
    [$owner] = navOwnerAtEmailStep();

    Livewire::actingAs($owner)->test(Wizard::class)
        ->assertSee('data-email-status', false)->assertSee('Not connected')
        ->assertSee("Without a working email account, follow-ups and estimates can't be sent", false);
});

// Test email

test('the test email goes to the owner, from the business sender, and nowhere else', function () {
    $provider = fakeEmailProvider();
    [$owner, $organization] = navOwnerAtEmailStep();
    navConnect($organization);

    Livewire::actingAs($owner)->test(Wizard::class)
        ->call('goBack')
        ->call('sendTestEmail')
        ->assertHasNoErrors()
        ->assertSee('Test email sent to '.$owner->email);

    expect($provider->sent)->toHaveCount(1)
        ->and($provider->sent[0]->toEmail)->toBe($owner->email)
        ->and($provider->sent[0]->fromEmail)->toBe('sales@dallashvac.com');
});

test('a test email from a connection that cannot send is refused with the reason, and nothing is sent', function () {
    $provider = fakeEmailProvider();
    [$owner, $organization] = navOwnerAtEmailStep();
    navConnect($organization, ['verification_status' => 'pending', 'verified_at' => null]);

    Livewire::actingAs($owner)->test(Wizard::class)
        ->call('sendTestEmail')
        ->assertSet('error', fn ($message) => filled($message));

    expect($provider->sent)->toBeEmpty();
});

test('a business without a connection cannot send a test email, and never uses another business\'s', function () {
    $provider = fakeEmailProvider();
    [$owner] = navOwnerAtEmailStep();

    [$other] = [User::factory()->owner()->create()];
    navConnect($other->organization);

    Livewire::actingAs($owner)->test(Wizard::class)
        ->call('sendTestEmail')
        ->assertSet('error', 'Connect an email account first, then send a test email.');

    expect($provider->sent)->toBeEmpty();
});
