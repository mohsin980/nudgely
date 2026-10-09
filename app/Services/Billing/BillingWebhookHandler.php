<?php

namespace App\Services\Billing;

use App\Billing\WebhookOutcome;
use App\Enums\Team\NotificationType;
use App\Exceptions\Billing\BillingException;
use App\Models\Organization;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified Stripe event into "re-read this subscription": the event only tells us which
 * customer and subscription changed; the state always comes from Stripe, so the handler is
 * idempotent and safe against out-of-order delivery. Events for customers that aren't ours
 * are ignored.
 */
class BillingWebhookHandler
{
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'customer.subscription.paused', 'customer.subscription.resumed', 'customer.subscription.trial_will_end',
    ];

    private const INVOICE_EVENTS = ['invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required'];

    public function __construct(
        private readonly BillingService $billing,
        private readonly TeamDirectory $team,
        private readonly TeamNotifier $notifier,
    ) {}

    public function handles(string $type): bool
    {
        return $type === 'checkout.session.completed' || in_array($type, self::SUBSCRIPTION_EVENTS, true) || in_array($type, self::INVOICE_EVENTS, true);
    }

    /**
     * @param  array<string, mixed>  $event  A Stripe event
     *
     * @throws BillingException
     */
    public function handle(array $event): WebhookOutcome
    {
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];

        if (! $this->handles($type)) {
            return WebhookOutcome::ignored('Event type is not handled.');
        }

        if (! is_array($object)) {
            return WebhookOutcome::ignored('Event has no object.');
        }

        $isSubscription = in_array($type, self::SUBSCRIPTION_EVENTS, true);
        $subscriptionId = $isSubscription ? ($object['id'] ?? null) : ($object['subscription'] ?? null);
        $customerId = is_string($object['customer'] ?? null) ? $object['customer'] : null;
        $organization = $this->billing->organizationForCustomer($this->billing->provider()->name(), $customerId);

        if ($organization === null) {
            Log::info('Billing webhook ignored: unknown customer.', ['type' => $type]);

            return WebhookOutcome::ignored('Customer does not belong to any organization.');
        }

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return WebhookOutcome::ignored('Event does not concern a subscription.', $organization->id);
        }

        $this->billing->syncFromProvider($organization, $subscriptionId, $type);

        if ($type === 'invoice.payment_failed') {
            $this->alertPaymentFailed($organization, (string) ($event['id'] ?? ''));
        }

        return WebhookOutcome::processed($organization->id);
    }

    private function alertPaymentFailed(Organization $organization, string $eventId): void
    {
        $owner = $this->team->owner($organization->id);

        if ($owner !== null) {
            $this->notifier->notify($organization, NotificationType::PaymentFailed, [$owner],
                'Your last payment didn\'t go through. Update your payment method to keep your plan.',
                route('settings.billing'), "payment-failed:{$eventId}");
        }
    }
}
