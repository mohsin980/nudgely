<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Billing\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\WebhookEvent;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppConfiguration;
use App\Support\CorrelationId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Meta's WhatsApp webhooks (https://developers.facebook.com/docs/graph-api/webhooks).
 *
 * GET proves the endpoint is ours (verify token + challenge). POST is authenticated by the X-Hub-Signature-256
 * HMAC of the raw body, using the app secret, and is stored before any work, like the email webhooks.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppConfiguration $configuration): Response
    {
        abort_unless($configuration->webhookReady(), 404);

        $expected = (string) config('whatsapp.verify_token');
        $mode = $request->query('hub_mode');
        $token = (string) $request->query('hub_verify_token', '');

        if ($mode !== 'subscribe' || ! hash_equals($expected, $token)) {
            Log::warning('webhook.rejected', ['event' => 'webhook.rejected', 'provider' => 'whatsapp', 'reason' => 'verification_failed', 'ip' => $request->ip()]);

            return response('Forbidden.', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppConfiguration $configuration): JsonResponse
    {
        abort_unless($configuration->webhookReady(), 404);

        $correlationId = CorrelationId::fromRequest($request);
        $body = $request->getContent();

        if (strlen($body) > (int) config('whatsapp.max_payload_kb') * 1024) {
            return $this->reject('payload_too_large', $correlationId, $request, 413, 'Payload too large.');
        }

        if (! $this->signatureIsValid($request, $body)) {
            return $this->reject('signature_failed', $correlationId, $request, 401, 'Unauthorized.');
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->reject('malformed_payload', $correlationId, $request, 422, 'Invalid WhatsApp payload.');
        }

        $digest = hash('sha256', $body);

        try {
            $event = DB::transaction(function () use ($payload, $digest, $correlationId) {
                $event = new WebhookEvent;
                $event->forceFill([
                    'provider' => WhatsAppConnection::PROVIDER,
                    'event_type' => WebhookEvent::TYPE_WHATSAPP,
                    // Meta sends no event ID, so the body digest identifies a delivery: a retried delivery matches.
                    'external_event_id' => $digest,
                    'payload' => $payload,
                    'payload_hash' => $digest,
                    'status' => WebhookEventStatus::Received,
                    'received_at' => now(),
                    'correlation_id' => $correlationId,
                ])->save();

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('webhook.duplicate', ['event' => 'webhook.duplicate', 'provider' => 'whatsapp', 'correlation_id' => $correlationId]);

            return response()->json(['message' => 'Already received.']);
        }

        ProcessWhatsAppWebhookJob::dispatch($event->id);

        Log::info('webhook.received', ['event' => 'webhook.received', 'provider' => 'whatsapp', 'webhook_event_id' => $event->id, 'correlation_id' => $correlationId]);

        return response()->json(['message' => 'Accepted.']);
    }

    private function signatureIsValid(Request $request, string $body): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');

        return str_starts_with($header, 'sha256=')
            && hash_equals('sha256='.hash_hmac('sha256', $body, (string) config('whatsapp.app_secret')), $header);
    }

    /**
     * Logged with a reason code only: never the body, the signature or the sender's content.
     */
    private function reject(string $reason, string $correlationId, Request $request, int $status, string $message): JsonResponse
    {
        Log::warning('webhook.rejected', ['event' => 'webhook.rejected', 'provider' => 'whatsapp', 'reason' => $reason, 'status' => $status, 'correlation_id' => $correlationId, 'ip' => $request->ip()]);

        return response()->json(['message' => $message], $status);
    }
}
