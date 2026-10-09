<?php

use App\Enums\Automation\AutomationStatus;
use App\Enums\Onboarding\OnboardingStep;
use App\Livewire\Dashboard;
use App\Livewire\Onboarding\Wizard;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Estimates\EstimateService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

function signUpOwner(string $business = 'Dallas HVAC', string $name = 'John Smith', string $email = 'john@dallashvac.com'): User
{
    test()->post('/register', ['business_name' => $business, 'name' => $name, 'email' => $email, 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertRedirect(route('onboarding.show'));

    return User::where('email', $email)->sole();
}

function ownerWizard(User $owner)
{
    return Livewire::actingAs($owner->fresh())->test(Wizard::class);
}

test('first end-to-end: Dallas HVAC goes from sign-up to a working follow-up automation', function () {
    $provider = fakeEmailProvider();
    $owner = signUpOwner();
    $organization = $owner->organization;
    expect($organization->onboarding_completed_at)->toBeNull();

    // Welcome → Step 1: business profile (Dallas HVAC, HVAC).
    $page = ownerWizard($owner)->assertSee('Welcome to QuoteFollow')->call('start')
        ->assertSee('About your business')->assertSet('businessName', 'Dallas HVAC')
        ->set('businessType', 'hvac')->set('website', 'dallashvac.com')->set('phone', '(214) 555-1234')->call('saveBusiness')->assertHasNoErrors();

    // Location: the state suggests the timezone.
    $page->assertSee('Where are you located?')->set('stateCode', 'TX')->assertSet('timezone', 'America/Chicago')->set('city', 'Dallas')->call('saveLocation')->assertHasNoErrors();

    // Step 2: email. Not connected yet, so the wizard waits; connecting sales@dallashvac.com lets it continue.
    $page->assertSee('Connect your business email')->assertSee('Not connected');
    EmailConnection::factory()->verified()->default()->create(['organization_id' => $organization->id, 'domain' => 'dallashvac.com', 'sender_email' => 'sales@dallashvac.com', 'sender_name' => 'Dallas HVAC']);

    // Step 3: customer Jane Smith.
    ownerWizard($owner)->assertSee('Add a customer to try QuoteFollow')->set('customerName', 'Jane Smith')->set('customerEmail', 'jane@example.com')->set('customerPhone', '(214) 555-0001')->call('addCustomer')->assertHasNoErrors();
    $jane = Customer::where('organization_id', $organization->id)->sole();

    // Step 4: estimate.
    ownerWizard($owner)->assertSee('Create your first estimate')->set('estimateCustomerId', (string) $jane->id)->set('estimateTitle', 'AC Installation')->set('estimatePrice', '2500')->call('createEstimate')->assertHasNoErrors();
    $estimate = Estimate::where('organization_id', $organization->id)->sole();

    // Step 5: Estimate Follow-Up, reviewed before it is activated.
    $page = ownerWizard($owner)->assertSee('Set up your first automation')->assertSee('Follow up after estimate')->assertSee('Recommended')->call('chooseTemplate', 'follow_up_after_estimate');
    $page->assertSee('Estimate sent')->assertSee('3 days')->assertSee('Customer has not replied')->assertSee('Send email')->assertSee('sales@dallashvac.com')->assertSee('Activate Automation');
    expect(Automation::where('organization_id', $organization->id)->count())->toBe(0); // reviewing creates nothing

    $page->set('confirmed', true)->call('activateAutomation')->assertHasNoErrors()->assertSee("You're ready to use QuoteFollow.", false);
    $automation = Automation::where('organization_id', $organization->id)->sole();
    expect($automation->status)->toBe(AutomationStatus::Active)->and($automation->wait_minutes)->toBe(3 * 1440);

    // Finish.
    expect(Message::where('organization_id', $organization->id)->count())->toBe(0); // onboarding itself sent nothing
    ownerWizard($owner)->assertSeeHtml('data-count="customers"')->call('finish')->assertRedirect(route('dashboard'));

    $organization = $organization->fresh();
    expect($organization->onboarding_completed_at)->not->toBeNull()->and($organization->onboarding_step)->toBe('completed')
        ->and($organization->business_type)->toBe('hvac')->and($organization->website)->toBe('https://dallashvac.com')->and($organization->timezone())->toBe('America/Chicago');

    // The dashboard: a working business, no longer the empty welcome, no redirect back to onboarding.
    $this->actingAs($owner->fresh())->get('/dashboard')->assertOk();
    Livewire::withoutLazyLoading()->actingAs($owner->fresh())->test(Dashboard::class)->assertDontSee('No customers yet.')->assertSee('Getting started')->assertSee('Invite a teammate');
    $summary = ownerWizardSummary($organization);
    expect($summary)->toBe(['customers' => 1, 'estimates' => 1, 'automations' => 1, 'follow_ups' => 0]);

    // Follow-up capability: when the owner sends the estimate (their own action), the automation takes over.
    $organization->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();
    app(EstimateService::class)->send($owner->fresh(), $estimate->fresh());
    expect(Message::where('organization_id', $organization->id)->where('to_address', 'jane@example.com')->count())->toBe(1)
        ->and(AutomationRun::where('automation_id', $automation->id)->count())->toBe(1); // waiting 3 days, then "no reply → send follow-up"
});

function ownerWizardSummary(Organization $organization): array
{
    return [
        'customers' => $organization->customers()->where('is_demo', false)->count(),
        'estimates' => $organization->estimates()->count(),
        'automations' => $organization->automations()->where('status', 'active')->count(),
        'follow_ups' => $organization->followUps()->count(),
    ];
}

test('second end-to-end: skipping customer, estimate and automation still reaches a clean dashboard', function () {
    $owner = signUpOwner('Austin Plumbing', 'Ana Ruiz', 'ana@austinplumbing.com');
    $organization = $owner->organization;

    ownerWizard($owner)->call('start')->set('businessType', 'plumbing')->call('saveBusiness')->call('skip', 'business_preferences')
        ->call('skip', 'email_connection')->call('skip', 'first_customer')->call('skip', 'first_estimate')->call('skip', 'first_automation')
        ->assertSee("You're ready to use QuoteFollow.", false)->call('finish')->assertRedirect(route('dashboard'));

    $this->actingAs($owner->fresh())->get('/dashboard')->assertOk();
    $organization = $organization->fresh();
    expect($organization->onboarding_completed_at)->not->toBeNull()
        ->and(Customer::where('organization_id', $organization->id)->count())->toBe(0) // no fake customer
        ->and(Estimate::where('organization_id', $organization->id)->count())->toBe(0)   // no fake estimate
        ->and(Automation::where('organization_id', $organization->id)->count())->toBe(0)  // no automation
        ->and($organization->emailConnections()->count())->toBe(0);

    // The dashboard invites the next steps instead of showing empty numbers, and the checklist remembers what is left.
    Livewire::withoutLazyLoading()->actingAs($owner->fresh())->test(Dashboard::class)->assertSee('Add Customer')->assertSee('Create Estimate')->assertSee('Create Automation')->assertSee('Getting started');
});

test('third end-to-end: leaving after business, email and customer resumes at the estimate', function () {
    $owner = signUpOwner();
    $organization = $owner->organization;
    ownerWizard($owner)->call('start')->set('businessType', 'hvac')->call('saveBusiness')->set('stateCode', 'TX')->call('saveLocation');
    EmailConnection::factory()->verified()->default()->create(['organization_id' => $organization->id, 'domain' => 'dallashvac.com', 'sender_email' => 'sales@dallashvac.com']);
    ownerWizard($owner)->set('customerName', 'Jane Smith')->set('customerEmail', 'jane@example.com')->call('addCustomer');

    // The owner closes the browser and comes back the next day.
    Auth::logout();
    session()->flush();
    $this->travel(1)->days();
    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-horse-battery'])->assertRedirect();
    $this->get('/dashboard')->assertRedirect(route('onboarding.show')); // not finished: back to the wizard

    $page = ownerWizard($owner)->assertSee('Create your first estimate')->assertDontSee('About your business')->assertDontSee('Add a customer to try QuoteFollow');
    $state = app(OnboardingService::class)->state($organization->fresh());
    expect($state->current)->toBe(OnboardingStep::FirstEstimate)
        ->and($state->isDone(OnboardingStep::BusinessProfile))->toBeTrue()->and($state->isDone(OnboardingStep::BusinessPreferences))->toBeTrue()
        ->and($state->isDone(OnboardingStep::EmailConnection))->toBeTrue()->and($state->isDone(OnboardingStep::FirstCustomer))->toBeTrue()
        ->and($state->isDone(OnboardingStep::FirstEstimate))->toBeFalse()->and($state->isDone(OnboardingStep::FirstAutomation))->toBeFalse();
    foreach (['Business', 'Email', 'Customer'] as $label) {
        expect($page->html())->toMatch('/data-group="'.$label.'" data-status="done"/');
    }
});

test('fourth end-to-end: only the owner is onboarded; manager and staff go straight to their business', function () {
    $owner = signUpOwner();
    $organization = $owner->organization;
    $manager = User::factory()->manager()->for($organization)->create(['name' => 'Sarah Wilson']);
    $staff = User::factory()->staff()->for($organization)->create(['name' => 'Mike Johnson']);
    $service = app(OnboardingService::class);

    expect($service->needs($owner->fresh()))->toBeTrue()->and($service->needs($manager->fresh()))->toBeFalse()->and($service->needs($staff->fresh()))->toBeFalse();

    $this->actingAs($owner->fresh())->get('/dashboard')->assertRedirect(route('onboarding.show'));
    $this->actingAs($owner->fresh())->get('/onboarding')->assertOk();
    foreach ([$manager, $staff] as $member) {
        $this->actingAs($member->fresh())->get('/dashboard')->assertOk();
        $this->actingAs($member->fresh())->get('/onboarding')->assertForbidden();
        expect($member->fresh()->organization_id)->toBe($organization->id);
    }

    // Everyone works in the same business; the owner finishing later doesn't change that for the team.
    $service->skipOnboarding($owner->fresh());
    $this->actingAs($owner->fresh())->get('/dashboard')->assertOk();
    expect(User::where('organization_id', $organization->id)->count())->toBe(3);

    // Another business's owner has their own, independent onboarding.
    Auth::logout();
    $other = signUpOwner('Other Co', 'Olivia Owner', 'olivia@other.com');
    expect($service->needs($other->fresh()))->toBeTrue()->and($other->organization_id)->not->toBe($organization->id);
});
