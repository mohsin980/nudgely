<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\Email\InvalidInboundEmailException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\WebhookEvent;
use App\Services\Email\Inbound\InboundEmailProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives inbound-email webhooks: authenticate, validate, store once, queue processing.
 */
class InboundEmailWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, InboundEmailProviderManager $providers): JsonResponse
    {
        abort_unless($providers->supports($provider), 404);
        $webhook = $providers->driver($provider);

        if (! $webhook->authenticate($request)) {
            Log::warning('Inbound email webhook authentication failed.', ['provider' => $provider, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $maxBytes = config('email.inbound.max_payload_kb') * 1024;

        if ((int) $request->header('Content-Length') > $maxBytes || strlen($request->getContent()) > $maxBytes) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $payload = json_decode($request->getContent(), true);

        try {
            $email = is_array($payload) ? $webhook->parse($payload) : throw new InvalidInboundEmailException('Body is not a JSON object.');
        } catch (InvalidInboundEmailException) {
            return response()->json(['message' => 'Invalid inbound email payload.'], 422);
        }

        try {
            // Its own transaction (a savepoint when nested) so a duplicate never aborts an outer one.
            $event = DB::transaction(function () use ($provider, $email, $webhook, $payload) {
                $event = new WebhookEvent;
                $event->forceFill([
                    'provider' => $provider,
                    'event_type' => WebhookEvent::TYPE_INBOUND_EMAIL,
                    'external_event_id' => $email->providerMessageId,
                    'payload' => $webhook->sanitizePayload($payload),
                ])->save();

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // A retried delivery of an email we already have: acknowledge it so the provider stops retrying.
            return response()->json(['message' => 'Already received.']);
        }

        ProcessInboundEmailJob::dispatch($event->id);

        Log::info('Inbound email webhook accepted.', [
            'provider' => $provider,
            'webhook_event_id' => $event->id,
            'provider_message_id' => $email->providerMessageId,
        ]);

        return response()->json(['message' => 'Accepted.']);
    }
}
