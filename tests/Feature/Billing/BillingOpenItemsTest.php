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
use App\Models\BillingWebhookEvent;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\OrganizationActivity;
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
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

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

// ─── Stripe webhooks ─────────────────────────────────────────────────────────────────────

function webhook(array $event, ?string $secret = 'whsec_test_secret', ?int $timestamp = null): TestResponse
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = $secret === null ? 'garbage' : "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', route('webhooks.billing.stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
}

function stripeEvent(string $type, array $object, ?string $id = null): array
{
    return ['id' => $id ?? 'evt_'.uniqid(), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
}

/**
 * The owner paid on Stripe's checkout page, but QuoteFollow hasn't recorded it yet.
 *
 * @return array{session: string, subscription: string, customer: string}
 */
function paidAtStripe(): array
{
    $session = app(BillingService::class)->startCheckout(test()->owner, 'starter', 'https://a', 'https://b');
    $remote = test()->stripe->completeCheckout($session->id);

    return ['session' => $session->id, 'subscription' => $remote['id'], 'customer' => test()->organization->fresh()->billing_customer_id];
}

describe('webhooks', function () {
    beforeEach(function () {
        $this->stripe = FakeStripe::install();
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    });

    test('signatures are verified on the raw body', function () {
        $event = stripeEvent('customer.subscription.updated', ['id' => 'sub_x', 'customer' => 'cus_x']);

        webhook($event, secret: 'whsec_wrong')->assertStatus(400);
        webhook($event, timestamp: time() - 3600)->assertStatus(400); // replayed
        $this->call('POST', route('webhooks.billing.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertStatus(400);
        webhook($event)->assertOk();

        config(['services.stripe.webhook_secret' => null]);
        webhook($event)->assertStatus(503);
        expect(BillingWebhookEvent::count())->toBe(1);
    });

    test('a completed checkout is recorded even if the customer never returned to the page', function () {
        $paid = paidAtStripe();
        expect(Subscription::count())->toBe(0);

        webhook(stripeEvent('checkout.session.completed', ['id' => $paid['session'], 'customer' => $paid['customer'], 'subscription' => $paid['subscription']]))->assertOk();

        $subscription = Subscription::sole();
        expect($subscription->organization_id)->toBe($this->organization->id)->and($subscription->provider_subscription_id)->toBe($paid['subscription'])
            ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')
            ->and(OrganizationActivity::where('action', 'subscription_started')->sole()->data['via'])->toBe('webhook');
    });

    test('an event is handled once however often Stripe delivers it', function () {
        $paid = paidAtStripe();
        $event = stripeEvent('customer.subscription.created', ['id' => $paid['subscription'], 'customer' => $paid['customer']], 'evt_same');

        webhook($event)->assertOk()->assertJson(['received' => true]);
        webhook($event)->assertOk()->assertJson(['duplicate' => true]);

        expect(BillingWebhookEvent::where('event_id', 'evt_same')->count())->toBe(1)->and(Subscription::count())->toBe(1)
            ->and(OrganizationActivity::where('action', 'subscription_started')->count())->toBe(1)
            ->and(BillingWebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    test('changes made at Stripe reach the local subscription', function () {
        $paid = paidAtStripe();
        webhook(stripeEvent('checkout.session.completed', ['customer' => $paid['customer'], 'subscription' => $paid['subscription']]));

        // Cancelled from Stripe's billing portal.
        $this->stripe->subscriptions[$paid['subscription']]['cancel_at_period_end'] = true;
        webhook(stripeEvent('customer.subscription.updated', ['id' => $paid['subscription'], 'customer' => $paid['customer']]))->assertOk();
        expect(Subscription::sole()->cancel_at_period_end)->toBeTrue();

        // Then the period ends and Stripe deletes it. The state is read from Stripe, so event order doesn't matter.
        $this->stripe->advancePastPeriodEnd($paid['subscription']);
        webhook(stripeEvent('customer.subscription.deleted', ['id' => $paid['subscription'], 'customer' => $paid['customer']]))->assertOk();
        expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Cancelled)->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free');
    });

    test('a failed payment moves the subscription to past due and alerts the owner once', function () {
        $paid = paidAtStripe();
        webhook(stripeEvent('checkout.session.completed', ['customer' => $paid['customer'], 'subscription' => $paid['subscription']]));
        $this->stripe->subscriptions[$paid['subscription']]['status'] = 'past_due';
        $failed = stripeEvent('invoice.payment_failed', ['customer' => $paid['customer'], 'subscription' => $paid['subscription']], 'evt_failed_1');

        webhook($failed)->assertOk();
        webhook($failed)->assertOk();

        expect(Subscription::sole()->status)->toBe(SubscriptionStatus::PastDue)
            ->and($this->owner->notifications()->count())->toBe(1)
            ->and($this->owner->notifications()->first()->data['message'])->toContain("payment didn't go through")
            ->and($this->manager->notifications()->count())->toBe(0);
    });

    test('events for other customers and unrelated events are acknowledged and ignored', function () {
        webhook(stripeEvent('customer.subscription.updated', ['id' => 'sub_unknown', 'customer' => 'cus_not_ours']))->assertOk();
        webhook(stripeEvent('charge.succeeded', ['id' => 'ch_1']))->assertOk();

        expect(Subscription::count())->toBe(0)->and(BillingWebhookEvent::where('processed_at', null)->count())->toBe(0);
    });

    test('a subscription can never be claimed by another organization through a webhook', function () {
        $paid = paidAtStripe();
        webhook(stripeEvent('checkout.session.completed', ['customer' => $paid['customer'], 'subscription' => $paid['subscription']]));
        ['owner' => $otherOwner, 'organization' => $other] = teamBusiness('Other Co');
        app(BillingService::class)->startCheckout($otherOwner, 'pro', 'https://a', 'https://b');

        $forged = stripeEvent('customer.subscription.updated', ['id' => $paid['subscription'], 'customer' => $other->fresh()->billing_customer_id]);
        webhook($forged)->assertStatus(500);

        expect(Subscription::sole()->organization_id)->toBe($this->organization->id)->and(BillingWebhookEvent::latest('id')->first()->failed_at)->not->toBeNull();
    });

    test('a processing failure is retried by Stripe and succeeds once the provider is back', function () {
        $paid = paidAtStripe();
        $event = stripeEvent('customer.subscription.created', ['id' => $paid['subscription'], 'customer' => $paid['customer']], 'evt_retry');

        $this->stripe->failWith(500);
        webhook($event)->assertStatus(500);
        $row = BillingWebhookEvent::sole();
        expect($row->processed_at)->toBeNull()->and($row->failed_at)->not->toBeNull()->and($row->attempts)->toBe(1)->and(Subscription::count())->toBe(0);

        $this->stripe->recover();
        webhook($event)->assertOk();
        expect($row->fresh()->processed_at)->not->toBeNull()->and($row->fresh()->attempts)->toBe(2)->and(Subscription::count())->toBe(1);
    });
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
