<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Billing\BillingException;
use App\Http\Controllers\Controller;
use App\Models\BillingWebhookEvent;
use App\Services\Billing\BillingWebhookHandler;
use App\Services\Billing\StripeWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhooks: verify the signature on the raw body, record each event once, re-read the
 * affected subscription. 400 for bad signatures; 500 on a processing failure so Stripe retries.
 */
class StripeWebhookController extends Controller
{
    private const MAX_BYTES = 1_048_576;

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

        if ($record->processed_at !== null) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $record->increment('attempts');

        try {
            $handler->handle($event);
        } catch (BillingException|\Throwable $e) {
            $record->forceFill(['failed_at' => now(), 'failure_reason' => mb_substr($e::class, 0, 255)])->save();
            Log::warning('Stripe webhook processing failed.', ['event_type' => $event['type'], 'exception' => $e::class]);
            report($e);

            return response()->json(['message' => 'Processing failed.'], 500);
        }

        $record->forceFill(['processed_at' => now(), 'failed_at' => null, 'failure_reason' => null])->save();

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function record(array $event): BillingWebhookEvent
    {
        $lookup = ['provider' => 'stripe', 'event_id' => $event['id']];

        try {
            return BillingWebhookEvent::query()->where($lookup)->first() ?? tap(new BillingWebhookEvent, fn ($row) => $row->forceFill($lookup + ['type' => mb_substr($event['type'], 0, 100)])->save());
        } catch (UniqueConstraintViolationException) {
            return BillingWebhookEvent::query()->where($lookup)->firstOrFail();
        }
    }
}
