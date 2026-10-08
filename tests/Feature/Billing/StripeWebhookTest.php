<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Billing\WebhookEventStatus;
use App\Models\BillingWebhookEvent;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Fakes\FakeStripe;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'organization' => $this->organization] = teamBusiness();
    $this->stripe = FakeStripe::install();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
});

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

/** A subscription that is live locally, as after the first webhook. */
function activeSubscription(): array
{
    $paid = paidAtStripe();
    webhook(stripeEvent('customer.subscription.created', ['id' => $paid['subscription'], 'customer' => $paid['customer']]))->assertOk();

    return $paid;
}

function subscriptionEvent(string $type, array $paid, ?string $id = null): array
{
    return stripeEvent($type, ['id' => $paid['subscription'], 'customer' => $paid['customer']], $id);
}

function invoiceEvent(string $type, array $paid, ?string $id = null): array
{
    return stripeEvent($type, ['customer' => $paid['customer'], 'subscription' => $paid['subscription']], $id);
}

test('1. a valid webhook is accepted and recorded', function () {
    $paid = paidAtStripe();

    webhook(stripeEvent('checkout.session.completed', ['id' => $paid['session'], 'customer' => $paid['customer'], 'subscription' => $paid['subscription']], 'evt_valid'))
        ->assertOk()->assertJson(['received' => true]);

    $row = BillingWebhookEvent::sole();
    expect($row->provider)->toBe('stripe')->and($row->provider_event_id)->toBe('evt_valid')->and($row->event_type)->toBe('checkout.session.completed')
        ->and($row->status)->toBe(WebhookEventStatus::Processed)->and($row->processed_at)->not->toBeNull()->and($row->organization_id)->toBe($this->organization->id)
        ->and($row->metadata)->toMatchArray(['customer_id' => $paid['customer'], 'subscription_id' => $paid['subscription']])
        ->and(Subscription::sole()->organization_id)->toBe($this->organization->id);
});

test('2. an invalid signature is rejected and nothing is recorded', function () {
    $paid = paidAtStripe();
    $event = subscriptionEvent('customer.subscription.created', $paid);

    webhook($event, secret: 'whsec_wrong')->assertStatus(400);
    webhook($event, secret: null)->assertStatus(400);
    webhook($event, timestamp: time() - 3600)->assertStatus(400); // replayed
    $this->call('POST', route('webhooks.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertStatus(400);

    expect(BillingWebhookEvent::count())->toBe(0)->and(Subscription::count())->toBe(0);

    config(['services.stripe.webhook_secret' => null]);
    webhook($event)->assertStatus(503);
});

test('3. a duplicate webhook is processed only once', function () {
    $paid = paidAtStripe();
    $event = subscriptionEvent('customer.subscription.created', $paid, 'evt_same');

    webhook($event)->assertOk()->assertJsonMissing(['duplicate' => true]);
    webhook($event)->assertOk()->assertJson(['duplicate' => true]);

    expect(BillingWebhookEvent::count())->toBe(1)->and(BillingWebhookEvent::sole()->attempts)->toBe(1)
        ->and(Subscription::count())->toBe(1)->and(OrganizationActivity::where('action', 'subscription_started')->count())->toBe(1);

    $row = new BillingWebhookEvent;
    expect(fn () => $row->forceFill(['provider' => 'stripe', 'provider_event_id' => 'evt_same', 'event_type' => 'x'])->save())->toThrow(UniqueConstraintViolationException::class);
});

test('3b. an event someone else is processing right now is not run again', function () {
    $paid = paidAtStripe();
    $event = subscriptionEvent('customer.subscription.created', $paid, 'evt_busy');
    BillingWebhookEvent::forceCreate(['provider' => 'stripe', 'provider_event_id' => 'evt_busy', 'event_type' => $event['type'], 'status' => 'processing', 'attempts' => 1]);

    webhook($event)->assertOk()->assertJson(['duplicate' => true]);
    expect(Subscription::count())->toBe(0);

    // A claim abandoned by a crashed worker is taken over.
    BillingWebhookEvent::query()->update(['updated_at' => now()->subMinutes(10)]);
    webhook($event)->assertOk();
    expect(Subscription::count())->toBe(1)->and(BillingWebhookEvent::sole()->status)->toBe(WebhookEventStatus::Processed);
});

test('4. subscription created is recorded and grants the plan', function () {
    $paid = paidAtStripe();

    webhook(subscriptionEvent('customer.subscription.created', $paid))->assertOk();

    $subscription = Subscription::sole();
    expect($subscription->organization_id)->toBe($this->organization->id)->and($subscription->provider_subscription_id)->toBe($paid['subscription'])
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('starter')
        ->and(OrganizationActivity::where('action', 'subscription_started')->sole()->data['via'])->toBe('webhook');
});

test('5. subscription updated reaches the local subscription', function () {
    $paid = activeSubscription();

    $this->stripe->subscriptions[$paid['subscription']]['cancel_at_period_end'] = true;
    webhook(subscriptionEvent('customer.subscription.updated', $paid))->assertOk();

    expect(Subscription::sole()->cancel_at_period_end)->toBeTrue();
});

test('6. subscription cancelled ends the plan', function () {
    $paid = activeSubscription();

    $this->stripe->advancePastPeriodEnd($paid['subscription']);
    $this->stripe->subscriptions[$paid['subscription']]['status'] = 'canceled';
    webhook(subscriptionEvent('customer.subscription.deleted', $paid))->assertOk();

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Cancelled)->and(app(EntitlementService::class)->plan($this->organization)->key)->toBe('free')
        ->and(OrganizationActivity::where('action', 'subscription_status_changed')->latest('id')->first()->data)->toMatchArray(['to' => 'cancelled', 'via' => 'webhook']);
});

test('7. payment succeeded keeps the subscription active', function () {
    $paid = activeSubscription();
    $this->stripe->subscriptions[$paid['subscription']]['status'] = 'active';

    webhook(invoiceEvent('invoice.paid', $paid))->assertOk();

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Active)->and($this->owner->notifications()->get()->pluck('data.kind')->all())->not->toContain('payment_failed', 'payment_recovered');
});

test('8. payment failed moves the subscription to past due and alerts the owner once', function () {
    $paid = activeSubscription();
    $this->stripe->subscriptions[$paid['subscription']]['status'] = 'past_due';
    $failed = invoiceEvent('invoice.payment_failed', $paid, 'evt_failed_1');

    webhook($failed)->assertOk();
    webhook($failed)->assertOk();

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($this->owner->notifications()->count())->toBe(1)
        ->and($this->owner->notifications()->first()->data['message'])->toContain("couldn't process your payment")
        ->and($this->manager->notifications()->count())->toBe(0);
});

test('9. payment recovered restores the active state', function () {
    $paid = activeSubscription();
    $this->stripe->subscriptions[$paid['subscription']]['status'] = 'past_due';
    webhook(invoiceEvent('invoice.payment_failed', $paid))->assertOk();
    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::PastDue);

    $this->stripe->subscriptions[$paid['subscription']]['status'] = 'active';
    webhook(invoiceEvent('invoice.paid', $paid))->assertOk();

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(OrganizationActivity::where('action', 'subscription_status_changed')->latest('id')->first()->data)->toMatchArray(['from' => 'past_due', 'to' => 'active']);
});

test('10. an unknown event is acknowledged, recorded as ignored and changes nothing', function () {
    webhook(stripeEvent('charge.succeeded', ['id' => 'ch_1'], 'evt_unknown'))->assertOk()->assertJson(['ignored' => true]);

    $row = BillingWebhookEvent::sole();
    expect($row->status)->toBe(WebhookEventStatus::Ignored)->and($row->detail)->toContain('not handled')->and(Subscription::count())->toBe(0);

    webhook(subscriptionEvent('customer.subscription.updated', ['subscription' => 'sub_unknown', 'customer' => 'cus_not_ours']))->assertOk();
    expect(BillingWebhookEvent::latest('id')->first()->status)->toBe(WebhookEventStatus::Ignored);
});

test('11. one organization can never affect another', function () {
    $paid = activeSubscription();
    ['owner' => $otherOwner, 'organization' => $other] = teamBusiness('Other Co');
    app(BillingService::class)->startCheckout($otherOwner, 'pro', 'https://a', 'https://b');
    $otherCustomer = $other->fresh()->billing_customer_id;

    // Another organization's customer id cannot take over this subscription.
    webhook(stripeEvent('customer.subscription.updated', ['id' => $paid['subscription'], 'customer' => $otherCustomer]))->assertStatus(500);
    expect(Subscription::sole()->organization_id)->toBe($this->organization->id)
        ->and(BillingWebhookEvent::latest('id')->first()->status)->toBe(WebhookEventStatus::Failed)
        ->and(app(EntitlementService::class)->plan($other)->key)->toBe('free');

    // A failed payment for this organization only alerts and changes this organization.
    $this->stripe->subscriptions[$paid['subscription']]['status'] = 'past_due';
    webhook(invoiceEvent('invoice.payment_failed', $paid))->assertOk();
    expect($otherOwner->notifications()->count())->toBe(0)->and($other->fresh()->subscriptions()->count())->toBe(0);
});

test('a processing failure is retried by Stripe and succeeds once the provider is back', function () {
    $paid = paidAtStripe();
    $event = subscriptionEvent('customer.subscription.created', $paid, 'evt_retry');

    $this->stripe->failWith(500);
    webhook($event)->assertStatus(500);
    $row = BillingWebhookEvent::sole();
    expect($row->status)->toBe(WebhookEventStatus::Failed)->and($row->processed_at)->toBeNull()->and($row->failed_at)->not->toBeNull()->and($row->attempts)->toBe(1)->and(Subscription::count())->toBe(0);

    $this->stripe->recover();
    webhook($event)->assertOk();
    expect($row->fresh()->status)->toBe(WebhookEventStatus::Processed)->and($row->fresh()->attempts)->toBe(2)->and(Subscription::count())->toBe(1);
});

test('Stripe states are normalized into QuoteFollow states', function (string $stripe, SubscriptionStatus $expected) {
    $paid = activeSubscription();
    $this->stripe->subscriptions[$paid['subscription']]['status'] = $stripe;

    webhook(subscriptionEvent('customer.subscription.updated', $paid))->assertOk();

    expect(Subscription::sole()->status)->toBe($expected);
})->with([
    ['active', SubscriptionStatus::Active],
    ['trialing', SubscriptionStatus::Trialing],
    ['past_due', SubscriptionStatus::PastDue],
    ['canceled', SubscriptionStatus::Cancelled],
    ['unpaid', SubscriptionStatus::Unpaid],
    ['incomplete', SubscriptionStatus::Incomplete],
]);

test('stored events and logs hold no payload details or secrets', function () {
    $paid = paidAtStripe();
    $event = stripeEvent('invoice.payment_failed', $paid + ['customer_email' => 'x@example.com', 'amount_due' => 2900, 'payment_method' => 'pm_secret'], 'evt_safe');
    $event['data']['object']['subscription'] = $paid['subscription'];

    webhook($event)->assertOk();

    $stored = json_encode(BillingWebhookEvent::sole()->getAttributes());
    expect($stored)->not->toContain('x@example.com')->not->toContain('pm_secret')->not->toContain('2900')->not->toContain('whsec_');
});
