<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Billing\WebhookEventStatus;
use App\Exceptions\Email\InvalidInboundEmailException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\WebhookEvent;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use App\Support\CorrelationId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives inbound-email webhooks: authenticate, validate, store once, queue processing.
 *
 * Storing happens before any business work, so the provider gets its answer quickly and a failure
 * later on never loses the email. A redelivery of a stored email is acknowledged and not processed again.
 */
class InboundEmailWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, InboundEmailProviderManager $providers): JsonResponse
    {
        abort_unless($providers->supports($provider), 404);

        $correlationId = CorrelationId::fromRequest($request);
        $webhook = $providers->driver($provider);

        if (! $webhook->authenticate($request)) {
            return $this->reject($provider, 'authentication_failed', $correlationId, 401, 'Unauthorized.', $request);
        }

        Log::info('webhook.verified', ['event' => 'webhook.verified', 'provider' => $provider, 'correlation_id' => $correlationId]);

        if (! $request->isJson()) {
            return $this->reject($provider, 'unsupported_content_type', $correlationId, 415, 'Unsupported content type.', $request);
        }

        $maxBytes = config('email.inbound.max_payload_kb') * 1024;
        $body = $request->getContent();

        if ((int) $request->header('Content-Length') > $maxBytes || strlen($body) > $maxBytes) {
            return $this->reject($provider, 'payload_too_large', $correlationId, 413, 'Payload too large.', $request);
        }

        $payload = json_decode($body, true);

        try {
            $email = is_array($payload) ? $webhook->parse($payload) : throw new InvalidInboundEmailException('Body is not a JSON object.');
        } catch (InvalidInboundEmailException) {
            return $this->reject($provider, 'malformed_payload', $correlationId, 422, 'Invalid inbound email payload.', $request);
        }

        try {
            // Its own transaction (a savepoint when nested) so a duplicate never aborts an outer one.
            $event = DB::transaction(function () use ($provider, $email, $webhook, $payload, $body, $correlationId) {
                $event = new WebhookEvent;
                $event->forceFill([
                    'provider' => $provider,
                    'event_type' => WebhookEvent::TYPE_INBOUND_EMAIL,
                    'external_event_id' => $email->providerMessageId,
                    'payload' => $webhook->sanitizePayload($payload),
                    'payload_hash' => hash('sha256', $body),
                    'status' => WebhookEventStatus::Received,
                    'received_at' => now(),
                    'correlation_id' => $correlationId,
                ])->save();

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // A retried delivery of an email we already stored. Acknowledge it so the provider stops retrying;
            // it is never processed twice. A failed original waits for an operator to replay it.
            $existing = WebhookEvent::query()->where('provider', $provider)
                ->where('event_type', WebhookEvent::TYPE_INBOUND_EMAIL)->where('external_event_id', $email->providerMessageId)->first();

            Log::info('webhook.duplicate', [
                'event' => 'webhook.duplicate',
                'provider' => $provider,
                'webhook_event_id' => $existing?->id,
                'status' => $existing?->status?->value,
                'correlation_id' => $correlationId,
            ]);

            return response()->json(['message' => 'Already received.']);
        }

        ProcessInboundEmailJob::dispatch($event->id);

        Log::info('webhook.received', [
            'event' => 'webhook.received',
            'provider' => $provider,
            'webhook_event_id' => $event->id,
            'correlation_id' => $correlationId,
        ]);

        return response()->json(['message' => 'Accepted.']);
    }

    /**
     * Rejections are logged with a reason code only: never the body, the credentials or the sender's content.
     */
    private function reject(string $provider, string $reason, string $correlationId, int $status, string $message, Request $request): JsonResponse
    {
        Log::warning('webhook.rejected', [
            'event' => 'webhook.rejected',
            'provider' => $provider,
            'reason' => $reason,
            'status' => $status,
            'correlation_id' => $correlationId,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => $message], $status);
    }
}
