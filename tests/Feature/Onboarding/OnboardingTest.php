<?php

use App\Billing\ProviderSubscription;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Onboarding\OnboardingStep;
use App\Enums\Team\MemberStatus;
use App\Events\EstimateSent;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\Estimates\EstimateException;
use App\Livewire\Dashboard;
use App\Livewire\Onboarding\Wizard;
use App\Models\Automation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationTemplates;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use App\Services\Customers\CustomerService;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

/** A freshly signed-up business: an owner and an organization that has just begun onboarding. */
function newBusiness(string $name = 'Dallas HVAC', string $owner = 'John Smith'): array
{
    $user = User::factory()->owner()->create(['name' => $owner]);
    $organization = $user->organization;
    $organization->forceFill(['name' => $name, 'timezone' => 'America/Chicago', 'automations_enabled' => true])->save();
    app(OnboardingService::class)->begin($organization);

    return ['owner' => $user->fresh(), 'organization' => $organization->fresh()];
}

function connectEmail(Organization $organization, string $email = 'sales@dallashvac.com'): EmailConnection
{
    return EmailConnection::factory()->verified()->default()->create([
        'organization_id' => $organization->id, 'domain' => explode('@', $email)[1], 'sender_email' => $email, 'sender_name' => $organization->name,
    ]);
}

function wizard(User $user)
{
    return Livewire::actingAs($user->fresh())->test(Wizard::class);
}

function withProfile(User $owner, string $type = 'hvac'): void
{
    app(OnboardingService::class)->saveBusinessProfile($owner, ['name' => $owner->organization->name, 'business_type' => $type, 'phone' => '(214) 555-1234', 'website' => 'dallashvac.com']);
}

// ─── Entering onboarding ─────────────────────────────────────────────────────────────────

test('1. a new owner is taken to onboarding after signing up and from the dashboard', function () {
    $this->post('/register', ['business_name' => 'Dallas HVAC', 'name' => 'John Smith', 'email' => 'john@dallashvac.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertRedirect(route('onboarding.show'));

    $organization = Organization::where('name', 'Dallas HVAC')->sole();
    expect($organization->onboarding_started_at)->not->toBeNull()->and($organization->onboarding_step)->toBe('business_profile')->and($organization->onboarding_completed_at)->toBeNull();

    $this->get('/dashboard')->assertRedirect(route('onboarding.show'));
    $this->get('/onboarding')->assertOk()->assertSee('Welcome to QuoteFollow');
});

test('2. people who join an existing business are never put through owner onboarding', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    $manager = User::factory()->manager()->for($organization)->create();
    $staff = User::factory()->staff()->for($organization)->create();

    foreach ([$manager, $staff] as $member) {
        $this->actingAs($member->fresh())->get('/dashboard')->assertOk();
        $this->actingAs($member->fresh())->get('/onboarding')->assertForbidden();
        expect(app(OnboardingService::class)->needs($member->fresh()))->toBeFalse();
    }

    expect(app(OnboardingService::class)->needs($owner))->toBeTrue();
});

test('3. the welcome page loads with progress and both choices', function () {
    ['owner' => $owner] = newBusiness();

    $this->actingAs($owner)->get('/onboarding')->assertOk();
    wizard($owner)->assertSee('Welcome to QuoteFollow')->assertSee("Let's get your business ready in a few minutes.", false)->assertSee('Get Started')->assertSee('Skip for now')
        ->assertSeeInOrder(['Business', 'Email', 'Customer', 'Estimate', 'Automation']);
});

// ─── Business ────────────────────────────────────────────────────────────────────────────

test('4. the business profile saves through the business settings service, with the website completed', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();

    wizard($owner)->call('start')->set('businessName', 'Dallas HVAC Co')->set('businessType', 'hvac')->set('website', 'dallashvac.com')->set('phone', '(214) 555-1234')->call('saveBusiness')->assertHasNoErrors();

    $organization = $organization->fresh();
    expect($organization->name)->toBe('Dallas HVAC Co')->and($organization->business_type)->toBe('hvac')->and($organization->website)->toBe('https://dallashvac.com')->and($organization->phone)->toBe('(214) 555-1234')
        ->and(OrganizationActivity::where('organization_id', $organization->id)->where('action', 'business_profile_updated')->exists())->toBeTrue();
});

test('5. the business type is required and must be one we offer', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();

    wizard($owner)->call('start')->set('businessType', '')->call('saveBusiness')->assertHasErrors('businessType')->assertSee('Choose the kind of business you run.');
    wizard($owner)->set('businessType', 'wizardry')->call('saveBusiness')->assertHasErrors('businessType');

    expect($organization->fresh()->business_type)->toBeNull();
});

test('location and timezone save through the preferences service; the state suggests a timezone that stays editable', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);

    $page = wizard($owner)->set('stateCode', 'CA')->assertSet('timezone', 'America/Los_Angeles');
    $page->set('timezone', 'America/Denver')->set('stateCode', 'NY')->assertSet('timezone', 'America/Denver'); // edited: not overridden
    $page->set('country', 'US')->set('stateCode', 'TX')->set('city', 'Dallas')->call('saveLocation')->assertHasNoErrors();

    $organization = $organization->fresh();
    expect($organization->state)->toBe('TX')->and($organization->city)->toBe('Dallas')->and($organization->timezone())->toBe('America/Denver')
        ->and($organization->onboarding_progress['business_preferences'])->toBe('done');
});

// ─── Email ───────────────────────────────────────────────────────────────────────────────

test('6. the email step shows not connected, and moves on by itself once an email is connected', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->saveLocation($owner, ['country' => 'US', 'state' => 'TX', 'city' => 'Dallas', 'timezone' => 'America/Chicago']);

    wizard($owner)->assertSee('Connect your business email')->assertSee('Not connected')->assertSee('Connect Email')->assertSee('Skip for now')
        ->assertSee('QuoteFollow can send follow-ups and estimates')->assertDontSee('Add a customer to try QuoteFollow');
    expect(app(OnboardingService::class)->state($organization->fresh())->current)->toBe(OnboardingStep::EmailConnection);

    connectEmail($organization);

    wizard($owner)->assertDontSee('Connect your business email')->assertSee('Add a customer to try QuoteFollow');
    expect(app(OnboardingService::class)->state($organization->fresh())->isDone(OnboardingStep::EmailConnection))->toBeTrue();
});

test('an unverified email is not treated as connected', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    EmailConnection::factory()->default()->create(['organization_id' => $organization->id, 'verification_status' => 'pending']);

    expect(app(OnboardingService::class)->state($organization->fresh())->isDone(OnboardingStep::EmailConnection))->toBeFalse();
});

// ─── Customer, estimate, automation ──────────────────────────────────────────────────────

test('7. the first customer is created through the customer service', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::EmailConnection);

    wizard($owner)->assertSee('Add a customer to try QuoteFollow')
        ->set('customerName', 'Jane Smith')->set('customerEmail', 'Jane@Example.com')->set('customerPhone', '(214) 555-0000')->call('addCustomer')->assertHasNoErrors();

    $customer = Customer::where('organization_id', $organization->id)->sole();
    expect($customer->first_name)->toBe('Jane')->and($customer->last_name)->toBe('Smith')->and($customer->email)->toBe('jane@example.com')->and($customer->is_demo)->toBeFalse()
        ->and(app(OnboardingService::class)->state($organization->fresh())->isDone(OnboardingStep::FirstCustomer))->toBeTrue();
});

test('8. the first estimate is a normal draft with the business defaults', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    $organization->forceFill(['settings' => $organization->businessSettings()->with(['estimate_notes' => 'Thanks for choosing us.', 'estimate_valid_days' => 14])->toArray()])->save();
    $customer = app(OnboardingService::class)->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::EmailConnection);

    wizard($owner)->assertSee('Create your first estimate')->assertSee('Dallas HVAC')->assertSee('USD')->assertSee('default notes included')
        ->set('estimateCustomerId', (string) $customer->id)->set('estimateTitle', 'AC Installation')->set('estimatePrice', '2500')->call('createEstimate')->assertHasNoErrors();

    $estimate = Estimate::where('organization_id', $organization->id)->sole();
    expect($estimate->customer_id)->toBe($customer->id)->and($estimate->isDraft())->toBeTrue()->and($estimate->title)->toBe('AC Installation')->and($estimate->notes)->toBe('Thanks for choosing us.')
        ->and($estimate->currency)->toBe('USD')->and($estimate->valid_until->toDateString())->toBe($organization->fresh()->localNow()->addDays(14)->toDateString());
});

test('9. automation templates are suggested for the business type, from data', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner, 'hvac');
    $hvac = array_keys(app(OnboardingService::class)->suggestedTemplates($organization->fresh()));

    ['owner' => $cleaner, 'organization' => $cleaning] = newBusiness('Sparkle Cleaning', 'Cara Lee');
    withProfile($cleaner, 'cleaning');
    $cleaner = array_keys(app(OnboardingService::class)->suggestedTemplates($cleaning->fresh()));

    expect($hvac[0])->toBe('follow_up_after_estimate')->and($cleaner[0])->toBe('estimate_follow_up')->and($hvac)->not->toBe($cleaner);
    foreach (array_merge(...array_column(config('onboarding.business_types'), 'templates')) as $key) {
        expect(AutomationTemplates::all())->toHaveKey($key); // every suggestion is a real template
    }
});

test('10. an automation needs explicit confirmation and shows exactly what it will do', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    connectEmail($organization, 'sales@dallashvac.com');
    $customer = app(OnboardingService::class)->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    app(OnboardingService::class)->createFirstEstimate($owner, ['customer_id' => $customer->id, 'title' => 'AC', 'unit_price' => '100']);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);

    $page = wizard($owner)->assertSee('Set up your first automation')->call('chooseTemplate', 'follow_up_after_estimate')
        ->assertSee('Estimate sent')->assertSee('3 days')->assertSee('Customer has not replied')->assertSee('Send email')->assertSee('sales@dallashvac.com')->assertSee("the customer's email address")
        ->assertSee('waits for your approval');

    // Not confirmed: nothing is created or activated.
    $page->call('activateAutomation')->assertHasErrors('confirmed');
    expect(Automation::where('organization_id', $organization->id)->count())->toBe(0);

    $page->set('confirmed', true)->call('activateAutomation')->assertHasNoErrors();
    $automation = Automation::where('organization_id', $organization->id)->sole();
    expect($automation->status)->toBe(AutomationStatus::Active)->and(OrganizationActivity::where('action', 'onboarding_automation_activated')->exists())->toBeTrue();
});

test('the review warns when there is no sender, and customizing creates only a draft', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    foreach ([OnboardingStep::BusinessPreferences, OnboardingStep::EmailConnection, OnboardingStep::FirstCustomer, OnboardingStep::FirstEstimate] as $step) {
        app(OnboardingService::class)->skipStep($owner, $step);
    }

    $page = wizard($owner)->call('chooseTemplate', 'follow_up_after_estimate')->assertSee('No email connected yet')->assertSee('Connect an email address first');
    $page->set('confirmed', true)->call('activateAutomation'); // activation refuses without a sender
    expect(Automation::where('organization_id', $organization->id)->where('status', AutomationStatus::Active)->count())->toBe(0);

    wizard($owner)->call('chooseTemplate', 'follow_up_after_estimate')->call('customizeAutomation');
    expect(Automation::where('organization_id', $organization->id)->sole()->status)->toBe(AutomationStatus::Draft);
});

// ─── Progress, resume, skip, completion ──────────────────────────────────────────────────

test('11 and 12. progress is stored on the organization and the wizard resumes where the business is', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->saveLocation($owner, ['country' => 'US', 'state' => 'TX', 'city' => 'Dallas', 'timezone' => 'America/Chicago']);
    connectEmail($organization);
    app(OnboardingService::class)->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    $organization = $organization->fresh();
    $state = app(OnboardingService::class)->state($organization);
    expect($state->current)->toBe(OnboardingStep::FirstEstimate)->and($organization->fresh()->onboarding_step)->toBe('first_estimate')
        ->and($state->percent())->toBe(67); // 4 of 6 steps really done

    // "Later": a brand-new browser session resumes at the estimate and shows what is done.
    Auth::logout();
    $page = wizard($owner)->assertSee('Create your first estimate')->assertDontSee('Add a customer to try QuoteFollow');
    expect($page->html())->toContain('67% complete');
    foreach (['Business', 'Email', 'Customer'] as $label) {
        expect($page->html())->toMatch('/data-group="'.$label.'" data-status="done"/');
    }
    expect($page->html())->toMatch('/data-group="Estimate" data-status="pending"/');
});

test('13. optional steps can be skipped; the business step cannot', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    $service = app(OnboardingService::class);

    expect(fn () => $service->skipStep($owner, OnboardingStep::BusinessProfile))->toThrow(ValidationException::class);

    wizard($owner)->call('skip', 'business_preferences')->call('skip', 'email_connection')->call('skip', 'first_customer')->call('skip', 'first_estimate')->call('skip', 'first_automation')
        ->assertSee("You're ready to use QuoteFollow.", false);

    expect(Customer::where('organization_id', $organization->id)->count())->toBe(0)->and(Estimate::where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Automation::where('organization_id', $organization->id)->count())->toBe(0)
        ->and($service->state($organization->fresh())->percent())->toBe(17); // skipping is not progress
});

test('14 and 15. finishing sets onboarding_completed_at and a finished business is never sent back', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);

    // Not finishable while steps remain.
    wizard($owner)->call('finish')->assertNoRedirect();
    expect($organization->fresh()->onboarding_completed_at)->toBeNull();

    foreach (['business_preferences', 'email_connection', 'first_customer', 'first_estimate', 'first_automation'] as $step) {
        app(OnboardingService::class)->skipStep($owner, OnboardingStep::from($step));
    }

    wizard($owner)->call('finish')->assertRedirect(route('dashboard'));
    expect($organization->fresh()->onboarding_completed_at)->not->toBeNull()->and(OrganizationActivity::where('action', 'onboarding_completed')->exists())->toBeTrue();

    $this->actingAs($owner->fresh())->get('/dashboard')->assertOk();
    $this->actingAs($owner->fresh())->get('/onboarding')->assertRedirect(route('dashboard'));
    expect(app(OnboardingService::class)->needs($owner->fresh()))->toBeFalse();
});

test('"skip for now" leaves onboarding without creating or changing anything, and does not loop', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();

    wizard($owner)->call('skipAll')->assertRedirect(route('dashboard'));

    $organization = $organization->fresh();
    expect($organization->onboarding_skipped_at)->not->toBeNull()->and($organization->onboarding_completed_at)->toBeNull()->and($organization->customers()->count())->toBe(0);
    $this->actingAs($owner->fresh())->get('/dashboard')->assertOk(); // no redirect loop
    $this->actingAs($owner->fresh())->get('/settings/security')->assertOk();
});

// ─── Navigation safety ───────────────────────────────────────────────────────────────────

test('billing, account security and sign out stay reachable during onboarding', function () {
    ['owner' => $owner] = newBusiness();

    $this->actingAs($owner)->get(route('settings.billing'))->assertOk();
    $this->actingAs($owner)->get(route('settings.security'))->assertOk();
    $this->actingAs($owner)->get(route('settings.email'))->assertOk()->assertDontSee('data-back-to-setup', false);
    $this->actingAs($owner)->get(route('settings.email', ['from' => 'onboarding']))->assertOk()->assertSee('Back to setup');
    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});

// ─── Security and tenancy ────────────────────────────────────────────────────────────────

test('16. two businesses have completely separate onboarding, profile, customers, estimates, automations and email', function () {
    ['owner' => $a, 'organization' => $orgA] = newBusiness('Dallas HVAC', 'John Smith');
    ['owner' => $b, 'organization' => $orgB] = newBusiness('Austin Plumbing', 'Ana Ruiz');
    withProfile($a, 'hvac');
    connectEmail($orgA, 'sales@dallashvac.com');
    $customer = app(OnboardingService::class)->createFirstCustomer($a, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    app(OnboardingService::class)->createFirstEstimate($a, ['customer_id' => $customer->id, 'title' => 'AC', 'unit_price' => '100']);

    $stateB = app(OnboardingService::class)->state($orgB->fresh());
    expect($stateB->current)->toBe(OnboardingStep::BusinessProfile)->and($orgB->fresh()->business_type)->toBeNull()->and($orgB->fresh()->name)->toBe('Austin Plumbing')
        ->and($orgB->customers()->count())->toBe(0)->and($orgB->estimates()->count())->toBe(0)->and($orgB->automations()->count())->toBe(0)->and($orgB->emailConnections()->count())->toBe(0)
        ->and(app(OnboardingService::class)->state($orgA->fresh())->current)->toBe(OnboardingStep::BusinessPreferences);

    // B cannot pick A's customer for an estimate, however the request is crafted.
    expect(fn () => app(OnboardingService::class)->createFirstEstimate($b, ['customer_id' => $customer->id, 'title' => 'Steal', 'unit_price' => '1']))->toThrow(EstimateException::class);
    wizard($b)->set('estimateCustomerId', (string) $customer->id);
    expect($orgB->estimates()->count())->toBe(0);
});

test('17 and 18. only the owner can change onboarding; managers and staff are refused', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    $manager = User::factory()->manager()->for($organization)->create();
    $staff = User::factory()->staff()->for($organization)->create();
    $service = app(OnboardingService::class);

    foreach ([$manager, $staff] as $member) {
        expect(fn () => $service->saveBusinessProfile($member, ['business_type' => 'hvac']))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->saveLocation($member, ['timezone' => 'America/Denver']))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->skipStep($member, OnboardingStep::EmailConnection))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->skipOnboarding($member))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->complete($member))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->createSampleCustomer($member))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->removeSampleData($member))->toThrow(AuthorizationException::class);
        Livewire::actingAs($member->fresh())->test(Wizard::class)->assertForbidden();
    }

    $organization = $organization->fresh();
    expect($organization->business_type)->toBeNull()->and($organization->onboarding_skipped_at)->toBeNull()->and($organization->onboarding_progress)->toBeNull();
});

test('a suspended or removed owner cannot use onboarding', function () {
    ['owner' => $owner] = newBusiness();
    $owner->forceFill(['status' => MemberStatus::Suspended])->save();

    $this->actingAs($owner->fresh())->get('/onboarding')->assertRedirect(route('login'));
});

test('the organization is never taken from the request', function () {
    ['owner' => $a, 'organization' => $orgA] = newBusiness();
    ['organization' => $orgB] = newBusiness('Other Co', 'Other Owner');

    Livewire::actingAs($a)->test(Wizard::class, ['organization_id' => $orgB->id])->set('businessType', 'hvac')->call('saveBusiness');

    expect($orgA->fresh()->business_type)->toBe('hvac')->and($orgB->fresh()->business_type)->toBeNull();
});

// ─── Sample data safety ──────────────────────────────────────────────────────────────────

test('sample customers are clearly marked, separate from real data, and removable', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    $service = app(OnboardingService::class);
    $real = $service->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    $sample = $service->createSampleCustomer($owner);
    expect($sample->is_demo)->toBeTrue()->and($sample->name)->toBe('Sample Customer')->and($sample->email)->toEndWith('@example.invalid')->and($service->createSampleCustomer($owner)->id)->toBe($sample->id)
        ->and(Customer::where('organization_id', $organization->id)->count())->toBe(2)
        ->and(app(UsageService::class)->usage($organization, LimitKey::Customers))->toBe(1) // only the real customer counts toward the plan
        ->and($service->state($organization->fresh())->isDone(OnboardingStep::FirstCustomer))->toBeTrue(); // from the real one

    $this->actingAs($owner)->get(route('customers.index'))->assertSee('Sample');
    $this->actingAs($owner)->get(route('customers.show', $sample->id))->assertSee('never receives real emails');

    // Removal also takes what was created for it, and never touches real data.
    app(OnboardingService::class)->createFirstEstimate($owner, ['customer_id' => $sample->id, 'title' => 'Demo', 'unit_price' => '10']);
    expect($service->removeSampleData($owner))->toBe(1);
    expect(Customer::where('organization_id', $organization->id)->pluck('id')->all())->toBe([$real->id])->and(Estimate::where('organization_id', $organization->id)->count())->toBe(0);
});

test('choosing the sample customer moves on without counting as a real customer', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::EmailConnection);

    wizard($owner)->assertSee('Create Sample Customer')->call('createSampleCustomer')->assertSee('Create your first estimate')->assertSee('Sample Customer (sample)');

    $state = app(OnboardingService::class)->state($organization->fresh());
    expect($state->status(OnboardingStep::FirstCustomer))->toBe('skipped')->and($organization->customers()->where('is_demo', false)->count())->toBe(0);
});

test('19. a sample customer can never be emailed, and nothing is queued or counted', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    connectEmail($organization);
    $sample = app(OnboardingService::class)->createSampleCustomer($owner);
    $provider = fakeEmailProvider();
    $before = app(UsageService::class)->usage($organization, LimitKey::OutboundEmails);

    expect(fn () => app(EmailService::class)->send($organization, $sample->email, 'Hi', '<p>Hi</p>', 'Hi'))->toThrow(EmailSendingNotAllowedException::class, 'sample customer')
        ->and(fn () => app(EmailService::class)->send($organization, strtoupper($sample->email), 'Hi', '<p>Hi</p>', 'Hi'))->toThrow(EmailSendingNotAllowedException::class);

    $conversation = Conversation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $sample->id]);
    expect(fn () => app(EmailService::class)->sendToConversation($conversation, 'Hi', '<p>Hi</p>', 'Hi'))->toThrow(EmailSendingNotAllowedException::class);

    $estimate = app(EstimateService::class)->create($owner, ['customer_id' => (string) $sample->id, 'conversation_id' => '', 'title' => 'Demo', 'notes' => '', 'valid_until' => now()->addDays(10)->toDateString(), 'discount_type' => '', 'discount_value' => '', 'tax_rate' => '',
        'items' => [['description' => 'Demo', 'quantity' => '1', 'unit_price' => '10']]]);
    expect(fn () => app(EstimateService::class)->send($owner, $estimate))->toThrow(EstimateException::class, 'sample customer');

    expect(Message::where('organization_id', $organization->id)->count())->toBe(0)->and(app(UsageService::class)->usage($organization, LimitKey::OutboundEmails))->toBe($before);
    expect($provider->sent ?? [])->toBe([]);
});

test('20. a sample customer never triggers automations', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    connectEmail($organization);
    $organization->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();
    $sample = app(OnboardingService::class)->createSampleCustomer($owner);
    $real = app(OnboardingService::class)->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    app(OnboardingService::class)->activateTemplate($owner, 'follow_up_after_estimate', true);
    $estimate = fn (Customer $c) => Estimate::factory()->create(['customer_id' => $c->id]);
    $engine = app(AutomationEngine::class);

    $demoEstimate = $estimate($sample);
    $demoRuns = $engine->evaluate(new EstimateSent($organization->id, $demoEstimate->id, $sample->id, null));
    $realEstimate = $estimate($real);
    $realRuns = $engine->evaluate(new EstimateSent($organization->id, $realEstimate->id, $real->id, null));

    expect($demoRuns)->toBe([])->and(count($realRuns))->toBe(1); // the same automation runs for a real customer, not for the sample
});

test('the sample customer email is undeliverable by construction', function () {
    ['owner' => $owner] = newBusiness();

    expect(app(OnboardingService::class)->createSampleCustomer($owner)->email)->toMatch('/^sample-[a-z0-9]{10}@example\.invalid$/');
});

// ─── Billing integration ─────────────────────────────────────────────────────────────────

test('21. the trial is shown during onboarding', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    app(BillingService::class)->startSignupTrial($organization);

    wizard($owner)->assertSee('Your trial ends in 14 days.')->assertSeeHtml('data-trial');
    $this->travel(5)->days();
    wizard($owner)->assertSee('Your trial ends in 9 days.');
});

test('22. a subscription that needs fixing sends the owner to billing first', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_x', 'starter', SubscriptionStatus::Incomplete));

    Livewire::actingAs($owner->fresh())->test(Wizard::class)->assertRedirect(route('settings.billing'));
});

test('23. onboarding reuses the billing services instead of duplicating billing logic', function () {
    $source = file_get_contents(app_path('Livewire/Onboarding/Wizard.php')).file_get_contents(app_path('Services/Onboarding/OnboardingService.php'));

    expect($source)->not->toContain('Stripe')->not->toContain('BillingProvider')->not->toContain('grace')->not->toContain('past_due')
        ->and(file_get_contents(resource_path('views/livewire/onboarding/wizard.blade.php')))->not->toContain('Stripe');
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    app(BillingService::class)->startSignupTrial($organization);
    expect(app(EntitlementService::class)->trial($organization->fresh())['days_left'])->toBe(14); // what the page shows comes from EntitlementService
});

test('the billing banner appears on the wizard for a payment problem', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    $subscription = app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_y', 'starter', SubscriptionStatus::PastDue, currentPeriodEnd: now()->addDays(20)->toImmutable()));

    $this->actingAs($owner->fresh())->get('/onboarding')->assertOk()->assertSee('data-billing-banner="past_due"', false);
});

// ─── UX ──────────────────────────────────────────────────────────────────────────────────

test('24. validation errors appear next to the field', function () {
    ['owner' => $owner] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::EmailConnection);

    wizard($owner)->set('customerName', '')->call('addCustomer')->assertHasErrors('customerName')->assertSee("Enter the customer's name.");
    wizard($owner)->set('customerName', 'Jane Smith')->set('customerEmail', 'not-an-email')->call('addCustomer')->assertHasErrors('customerEmail');
    wizard($owner)->set('businessName', '')->set('businessType', 'hvac')->call('saveBusiness')->assertHasErrors('businessName');
});

test('25. submit buttons are disabled while saving, and a double submit creates one record', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::EmailConnection);

    $page = wizard($owner);
    expect($page->html())->toContain('wire:loading.attr="disabled"');

    $page->set('customerName', 'Jane Smith')->set('customerEmail', 'jane@example.com')->call('addCustomer');
    $page->set('customerName', 'Jane Smith')->set('customerEmail', 'jane@example.com')->call('addCustomer'); // a second click arriving late

    expect(Customer::where('organization_id', $organization->id)->count())->toBe(1);
});

test('26. a failed step keeps the user there with a clear error and loses no progress', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->saveLocation($owner, ['country' => 'US', 'state' => 'TX', 'city' => 'Dallas', 'timezone' => 'America/Chicago']);
    $before = $organization->fresh()->only(['business_type', 'onboarding_progress', 'onboarding_step']);

    // An unexpected failure while saving a customer.
    $this->mock(CustomerService::class)->shouldReceive('create')->andThrow(new RuntimeException('database exploded'));
    app()->forgetInstance(OnboardingService::class);
    app()->forgetInstance(OnboardingService::class);

    wizard($owner)->set('customerName', 'Jane Smith')->call('skip', 'email_connection')->call('addCustomer')
        ->assertSee('Something went wrong. Your progress is saved')->assertDontSee('database exploded')->assertSee('Add a customer to try QuoteFollow');

    $after = $organization->fresh();
    expect($after->business_type)->toBe($before['business_type'])->and($after->onboarding_progress['business_preferences'])->toBe('done')
        ->and(app(OnboardingService::class)->state($after)->isDone(OnboardingStep::BusinessProfile))->toBeTrue();
});

test('a plan limit error stays on the step with its message', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::BusinessPreferences);
    app(OnboardingService::class)->skipStep($owner, OnboardingStep::EmailConnection);
    tightFreePlan(['customers' => 0]);

    wizard($owner)->set('customerName', 'Jane Smith')->set('customerEmail', 'jane@example.com')->call('addCustomer')->assertSee('allows up to 0 customers')->assertSee('Add a customer to try QuoteFollow');
    expect(Customer::where('organization_id', $organization->id)->count())->toBe(0);
});

// ─── Dashboard: checklist and empty state ────────────────────────────────────────────────

test('the dashboard checklist shows what is left and disappears when everything is done', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    app(OnboardingService::class)->skipOnboarding($owner);
    withProfile($owner);
    connectEmail($organization);
    $customer = app(OnboardingService::class)->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    app(OnboardingService::class)->createFirstEstimate($owner, ['customer_id' => $customer->id, 'title' => 'AC', 'unit_price' => '100']);

    $items = app(OnboardingService::class)->checklist($owner->fresh());
    expect(collect($items)->pluck('done', 'label')->all())->toBe(['Business profile' => true, 'Email connected' => true, 'First customer' => true, 'First estimate' => true, 'Create an automation' => false, 'Invite a teammate' => false]);
    Livewire::withoutLazyLoading()->actingAs($owner->fresh())->test(Dashboard::class)->assertSee('Getting started')->assertSee('Create an automation')->assertSee('Invite a teammate');

    app(OnboardingService::class)->installTemplate($owner, 'estimate_accepted');
    User::factory()->staff()->for($organization)->create();

    expect(app(OnboardingService::class)->checklist($owner->fresh()))->toBeNull()->and($organization->fresh()->onboarding_checklist_done_at)->not->toBeNull();
    Livewire::withoutLazyLoading()->actingAs($owner->fresh())->test(Dashboard::class)->assertDontSee('Getting started');
});

test('the checklist is for the owner only and never appears for other members', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    $manager = User::factory()->manager()->for($organization)->create();

    expect(app(OnboardingService::class)->checklist($manager->fresh()))->toBeNull()->and(app(OnboardingService::class)->checklist($owner->fresh()))->not->toBeNull();
});

test('an empty dashboard offers useful actions instead of empty charts', function () {
    ['owner' => $owner] = newBusiness();
    app(OnboardingService::class)->skipOnboarding($owner);

    Livewire::withoutLazyLoading()->actingAs($owner->fresh())->test(Dashboard::class)->assertSee('Add Customer')->assertSee('Create Estimate')->assertSee('Create Automation')->assertSee("Let's get your first customer workflow running.", false);
});

// ─── Reset command ───────────────────────────────────────────────────────────────────────

test('30. onboarding can be restarted only by a console command, without deleting business data', function () {
    ['owner' => $owner, 'organization' => $organization] = newBusiness();
    withProfile($owner);
    $customer = app(OnboardingService::class)->createFirstCustomer($owner, ['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    $organization->forceFill(['onboarding_completed_at' => now()])->save();

    $this->artisan('onboarding:reset', ['organization' => $organization->id])->expectsOutputToContain('Onboarding restarted')->assertSuccessful();

    $organization = $organization->fresh();
    expect($organization->onboarding_completed_at)->toBeNull()->and($organization->business_type)->toBeNull()->and($organization->onboarding_step)->toBe('business_profile')
        ->and(Customer::find($customer->id))->not->toBeNull();
    $this->artisan('onboarding:reset', ['organization' => 99999])->assertFailed();

    // No page or action resets it.
    $routes = collect(app('router')->getRoutes()->getRoutes())->map->uri()->implode(' ');
    expect($routes)->not->toContain('reset');
    expect(file_get_contents(resource_path('views/livewire/onboarding/wizard.blade.php')))->not->toContain('Reset');
});

test('the reset command refuses to run in production without --force', function () {
    ['organization' => $organization] = newBusiness();
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan('onboarding:reset', ['organization' => $organization->id])->expectsOutputToContain('Refusing to reset')->assertFailed();
});
