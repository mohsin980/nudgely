<?php

use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\Estimates\EstimateException;
use App\Exceptions\Team\TeamActionException;
use App\Livewire\Automations\AutomationIndex;
use App\Livewire\Customers\CustomerForm;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Automation\AutomationBuilder;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Customers\CustomerService;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use App\Services\Team\InvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness();
    $this->billing = app(BillingService::class);
});

/**
 * Small Free-plan limits so limit tests stay fast, with enforcement switched on.
 *
 * @param  array<string, int>  $limits
 */
function tightFreePlan(array $limits): void
{
    foreach ($limits as $key => $value) {
        config(["billing.plans.free.limits.{$key}" => $value]);
    }

    config(['billing.enforce_limits' => true]);
    app()->forgetInstance(PlanCatalog::class);
}

function moveToPlan(Organization $organization, string $plan): Subscription
{
    return app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_'.uniqid(), $plan, SubscriptionStatus::Active,
        currentPeriodStart: CarbonImmutable::now(), currentPeriodEnd: CarbonImmutable::now()->addMonth()));
}

// ─── Trial-ending reminder ───────────────────────────────────────────────────────────────

test('the owner is reminded once, a few days before the free trial ends', function () {
    $this->organization->forceFill(['trial_ends_at' => now()->addDays(2)])->save();

    $this->artisan('billing:send-trial-reminders')->expectsOutputToContain('1 trial reminder(s) sent.')->assertSuccessful();

    $notification = $this->owner->notifications()->sole();
    expect($notification->data['message'])->toContain('Your free trial ends in 2 days')->and($notification->data['url'])->toBe(route('settings.billing'))
        ->and($this->manager->notifications()->count())->toBe(0)
        ->and($this->organization->fresh()->trial_reminder_sent_at)->not->toBeNull();

    $this->artisan('billing:send-trial-reminders')->expectsOutputToContain('0 trial reminder(s) sent.');
    expect($this->owner->notifications()->count())->toBe(1);
});

test('no reminder for trials that are far off, over, or already converted', function () {
    $far = teamBusiness('Far Co');
    $far['organization']->forceFill(['trial_ends_at' => now()->addDays(10)])->save();
    $over = teamBusiness('Over Co');
    $over['organization']->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $paid = teamBusiness('Paid Co');
    $paid['organization']->forceFill(['trial_ends_at' => now()->addDay()])->save();
    moveToPlan($paid['organization'], 'starter');

    $this->artisan('billing:send-trial-reminders')->expectsOutputToContain('0 trial reminder(s) sent.');

    foreach ([$far, $over, $paid] as $business) {
        expect($business['owner']->notifications()->count())->toBe(0);
    }
});

// ─── Plan limit enforcement ──────────────────────────────────────────────────────────────

describe('limits', function () {
    test('customers: adding is refused at the limit, existing data stays, upgrading allows more', function () {
        tightFreePlan(['customers' => 3]);
        Customer::factory()->count(2)->for($this->organization)->create(); // + teamBusiness's customer = 3

        $add = fn (string $email) => app(CustomerService::class)->create($this->owner->fresh(), ['first_name' => 'New', 'last_name' => 'Person', 'email' => $email]);

        expect(fn () => $add('new@example.com'))->toThrow(ValidationException::class, 'Your Free plan allows up to 3 customers. Upgrade your plan to add more.')
            ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(3);

        moveToPlan($this->organization, 'starter');
        expect($add('new@example.com')->email)->toBe('new@example.com');

        // Downgrading never deletes: over the limit, only adding is refused.
        Subscription::query()->update(['status' => SubscriptionStatus::Cancelled, 'ended_at' => now()]);
        expect(Customer::where('organization_id', $this->organization->id)->count())->toBe(4)
            ->and(fn () => $add('another@example.com'))->toThrow(ValidationException::class);
    });

    test('the customer form shows the limit message', function () {
        tightFreePlan(['customers' => 1]);

        Livewire::actingAs($this->owner)->test(CustomerForm::class)
            ->set('first_name', 'Over')->set('last_name', 'Limit')->set('email', 'over@example.com')->call('save')
            ->assertHasErrors('email')->assertSee('Your Free plan allows up to 1 customers');
    });

    test('estimates: new estimates count per month, revisions do not', function () {
        tightFreePlan(['estimates' => 2]);
        $create = fn () => app(EstimateService::class)->create($this->owner->fresh(), estimateInput($this->customer));

        $first = $create();
        $create();
        expect(fn () => $create())->toThrow(EstimateException::class, 'allows up to 2 new estimates a month');

        // A revision of a sent estimate is not a new estimate.
        Estimate::factory()->create(['customer_id' => $this->customer->id, 'revision' => 2, 'estimate_number' => $first->estimate_number, 'revision_of_id' => $first->id]);
        expect(fn () => $create())->toThrow(EstimateException::class);

        $this->travel(1)->month();
        expect($create()->revision)->toBe(1);
    });

    test('automations: a new, restored or duplicated automation needs a free slot; archived ones do not count', function () {
        tightFreePlan(['automations' => 2]);
        $builder = app(AutomationBuilder::class);
        $input = fn (string $name) => ['name' => $name, 'trigger_type' => 'estimate_sent', 'conditions' => [], 'actions' => [['type' => 'create_task', 'configuration' => ['title' => 'Call']]]];

        $first = $builder->save($this->organization, $this->owner, $input('One'));
        $second = $builder->save($this->organization, $this->owner, $input('Two'));
        expect(fn () => $builder->save($this->organization, $this->owner, $input('Three')))->toThrow(PlanLimitException::class, 'allows up to 2 active automations')
            ->and(fn () => $builder->duplicate($first, $this->owner))->toThrow(PlanLimitException::class);

        // Editing an existing one is always fine; archiving frees a slot; restoring needs one again.
        expect($builder->save($this->organization, $this->owner, $input('One renamed'), $first)->name)->toBe('One renamed');
        $builder->archive($second, $this->owner);
        $third = $builder->save($this->organization, $this->owner, $input('Three'));
        expect($third->status)->toBe(AutomationStatus::Draft)->and(fn () => $builder->restore($second->fresh(), $this->owner))->toThrow(PlanLimitException::class);

        // The pages show the message instead of failing.
        Livewire::actingAs($this->owner)->test(AutomationIndex::class)->call('installTemplate', 'ready_to_book')->assertSee('allows up to 2 active automations');
        expect(Automation::where('organization_id', $this->organization->id)->count())->toBe(3);
    });

    test('team: open invitations hold a seat, and a seat is needed to accept', function () {
        // Free allows 1 member: the owner already uses it.
        $solo = User::factory()->owner()->create();
        tightFreePlan([]);
        expect(fn () => app(InvitationService::class)->invite($solo, 'Sam', 'sam@example.com', OrganizationRole::Staff))->toThrow(TeamActionException::class, 'allows up to 1 team members');

        // Starter allows 3: owner + one invited = 2, a second invitation fills it, a third is refused.
        moveToPlan($solo->organization, 'starter');
        $invite = fn (string $email) => app(InvitationService::class)->invite($solo->fresh(), 'Person', $email, OrganizationRole::Staff);
        $first = $invite('a@example.com');
        $invite('b@example.com');
        expect(fn () => $invite('c@example.com'))->toThrow(TeamActionException::class, 'allows up to 3 team members');

        // The plan shrinks before the invitation is accepted: no seat, no account.
        Subscription::query()->update(['status' => SubscriptionStatus::Cancelled, 'ended_at' => now()]);
        expect(fn () => app(InvitationService::class)->accept(basename($first['link']), 'Person', 'long-secure-password-1'))->toThrow(TeamActionException::class, 'allows up to 1 team members')
            ->and(User::where('email', 'a@example.com')->exists())->toBeFalse();
    });

    test('email: customer emails count per month; invitations and team notices never block', function () {
        fakeEmailProvider();
        tightFreePlan(['outbound_emails' => 2]);
        $send = fn (array $metadata = []) => app(EmailService::class)->send($this->organization, 'pat@example.com', 'Hello', '<p>Hi</p>', 'Hi', metadata: $metadata);

        $send();
        $send();
        expect(fn () => $send())->toThrow(EmailSendingNotAllowedException::class, 'allows up to 2 outbound emails a month');

        // Team invitations and notices are never held back by the limit.
        expect($send(['type' => 'team_invitation'])->id)->not->toBeNull()->and($send(['type' => 'team_notification'])->id)->not->toBeNull();

        // A new month starts fresh; an upgrade raises the limit.
        $this->travel(1)->month();
        expect($send()->id)->not->toBeNull();
    });

    test('enforcement can be switched off and unlimited plans are never limited', function () {
        tightFreePlan(['customers' => 1]);
        config(['billing.enforce_limits' => false]);
        $entitlements = app(EntitlementService::class);
        $entitlements->assertAllows($this->organization, LimitKey::Customers, 1000);

        config(['billing.enforce_limits' => true, 'billing.plans.free.limits.customers' => null]);
        app()->forgetInstance(PlanCatalog::class);
        app(EntitlementService::class)->assertAllows($this->organization, LimitKey::Customers, 1_000_000);
        expect(true)->toBeTrue();
    });

    test('limits are per organization', function () {
        tightFreePlan(['customers' => 1]);
        ['organization' => $other, 'owner' => $otherOwner] = teamBusiness('Other Co');
        moveToPlan($other, 'pro');

        expect(fn () => app(CustomerService::class)->create($this->owner->fresh(), ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@x.com']))->toThrow(ValidationException::class)
            ->and(app(CustomerService::class)->create($otherOwner->fresh(), ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@x.com'])->organization_id)->toBe($other->id);
    });
});
