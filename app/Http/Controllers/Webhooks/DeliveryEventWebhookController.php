<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Billing\WebhookEventStatus;
use App\Exceptions\Email\InvalidDeliveryEventException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeliveryEventJob;
use App\Models\WebhookEvent;
use App\Services\Email\Events\PostmarkDeliveryEvents;
use App\Support\CorrelationId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives provider delivery events. Same order as inbound mail: verify, validate, store, answer, then
 * process in a job. A repeated event is acknowledged and never applied twice.
 */
class DeliveryEventWebhookController extends Controller
{
    private const MAX_BYTES = 262144;

    public function __invoke(Request $request, string $provider, PostmarkDeliveryEvents $events): JsonResponse
    {
        abort_unless($provider === 'postmark', 404);

        $correlationId = CorrelationId::fromRequest($request);

        if (! $events->authenticate($request)) {
            return $this->reject($provider, 'authentication_failed', $correlationId, 401, $request);
        }

        Log::info('webhook.verified', ['event' => 'webhook.verified', 'provider' => $provider, 'correlation_id' => $correlationId]);

        $body = $request->getContent();

        if (! $request->isJson()) {
            return $this->reject($provider, 'unsupported_content_type', $correlationId, 415, $request);
        }

        if (strlen($body) > self::MAX_BYTES) {
            return $this->reject($provider, 'payload_too_large', $correlationId, 413, $request);
        }

        $payload = json_decode($body, true);

        try {
            $delivery = is_array($payload) ? $events->parse($payload) : throw new InvalidDeliveryEventException('Body is not a JSON object.');
        } catch (InvalidDeliveryEventException) {
            return $this->reject($provider, 'malformed_payload', $correlationId, 422, $request);
        }

        try {
            $event = DB::transaction(function () use ($provider, $delivery, $payload, $body, $correlationId) {
                $event = new WebhookEvent;
                $event->forceFill([
                    'provider' => $provider,
                    'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT,
                    'external_event_id' => $delivery->externalId,
                    // A bounce carries the bounced message; only what's needed for the status is kept.
                    'payload' => array_diff_key($payload, ['Content' => true, 'Details' => true]),
                    'payload_hash' => hash('sha256', $body),
                    'status' => WebhookEventStatus::Received,
                    'received_at' => now(),
                    'correlation_id' => $correlationId,
                ])->save();

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('webhook.duplicate', ['event' => 'webhook.duplicate', 'provider' => $provider, 'correlation_id' => $correlationId, 'external_event_id' => $delivery->externalId]);

            return response()->json(['message' => 'Already received.']);
        }

        ProcessDeliveryEventJob::dispatch($event->id);

        Log::info('webhook.received', ['event' => 'webhook.received', 'provider' => $provider, 'webhook_event_id' => $event->id, 'correlation_id' => $correlationId]);

        return response()->json(['message' => 'Accepted.']);
    }

    private function reject(string $provider, string $reason, string $correlationId, int $status, Request $request): JsonResponse
    {
        Log::warning('webhook.rejected', [
            'event' => 'webhook.rejected',
            'provider' => $provider,
            'reason' => $reason,
            'status' => $status,
            'correlation_id' => $correlationId,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Rejected.'], $status);
    }
}
