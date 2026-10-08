<?php

namespace App\Http\Controllers\Webhooks;

use App\Billing\WebhookOutcome;
use App\Enums\Billing\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingWebhookEvent;
use App\Services\Billing\BillingWebhookHandler;
use App\Services\Billing\StripeWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/stripe: verify the signature on the raw body, record each event once, claim it
 * atomically so concurrent deliveries run it once, then re-read the affected subscription.
 * 400 for bad signatures; 500 on a processing failure so Stripe retries.
 */
class StripeWebhookController extends Controller
{
    private const MAX_BYTES = 1_048_576;

    /** A claim older than this belongs to a worker that died; another delivery may take over. */
    private const STALE_CLAIM_MINUTES = 5;

    public function __invoke(Request $request, StripeWebhookVerifier $verifier, BillingWebhookHandler $handler): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (blank($secret)) {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured.');

            return response()->json(['message' => 'Webhooks are not configured.'], 503);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BYTES || ! $verifier->verify($body, $request->header('Stripe-Signature'), $secret)) {
            Log::warning('Stripe webhook signature verification failed.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['message' => 'Invalid event.'], 400);
        }

        $record = $this->record($event);

        if (! $this->claim($record)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            $outcome = $handler->handle($event);
        } catch (\Throwable $e) {
            $record->forceFill(['status' => WebhookEventStatus::Failed, 'failed_at' => now(), 'detail' => mb_substr($e::class, 0, 255)])->save();
            Log::warning('Stripe webhook processing failed.', ['event_type' => $event['type'], 'exception' => $e::class]);
            report($e);

            return response()->json(['message' => 'Processing failed.'], 500);
        }

        $this->finish($record, $outcome);

        return response()->json(['received' => true] + ($outcome->status === WebhookEventStatus::Ignored ? ['ignored' => true] : []));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function record(array $event): BillingWebhookEvent
    {
        $lookup = ['provider' => 'stripe', 'provider_event_id' => $event['id']];

        try {
            return BillingWebhookEvent::query()->where($lookup)->first()
                ?? tap(new BillingWebhookEvent, fn ($row) => $row->forceFill($lookup + ['event_type' => mb_substr($event['type'], 0, 100), 'metadata' => $this->metadata($event)])->save());
        } catch (UniqueConstraintViolationException) {
            return BillingWebhookEvent::query()->where($lookup)->firstOrFail();
        }
    }

    /**
     * Take the event for processing. A single conditional UPDATE decides the winner, so two
     * simultaneous deliveries can't both run it; handled events are never claimed again.
     */
    private function claim(BillingWebhookEvent $record): bool
    {
        return BillingWebhookEvent::query()->whereKey($record->id)
            ->where(fn ($query) => $query
                ->whereIn('status', [WebhookEventStatus::Received->value, WebhookEventStatus::Failed->value])
                ->orWhere(fn ($stale) => $stale->where('status', WebhookEventStatus::Processing->value)->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->update(['status' => WebhookEventStatus::Processing->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1
            && $record->refresh() !== null;
    }

    private function finish(BillingWebhookEvent $record, WebhookOutcome $outcome): void
    {
        $record->forceFill([
            'status' => $outcome->status,
            'organization_id' => $outcome->organizationId,
            'detail' => $outcome->reason,
            'processed_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    /**
     * Just enough to debug with: ids and states, never amounts, emails, addresses or payment details.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function metadata(array $event): array
    {
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $isSubscription = str_starts_with($event['type'], 'customer.subscription.');

        return array_filter([
            'object_type' => is_string($object['object'] ?? null) ? $object['object'] : null,
            'customer_id' => is_string($object['customer'] ?? null) ? $object['customer'] : null,
            'subscription_id' => $isSubscription ? ($object['id'] ?? null) : ($object['subscription'] ?? null),
            'object_status' => is_string($object['status'] ?? null) ? $object['status'] : null,
            'livemode' => is_bool($event['livemode'] ?? null) ? $event['livemode'] : null,
            'api_version' => is_string($event['api_version'] ?? null) ? $event['api_version'] : null,
        ], fn ($value) => $value !== null && is_scalar($value));
    }
}
