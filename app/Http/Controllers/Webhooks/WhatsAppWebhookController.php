<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The address each business pastes into its Meta app as the WhatsApp webhook.
 *
 * GET: Meta's one-time check. It sends the verify token the business copied from QuoteFlow; the matching
 * business's challenge is echoed back. POST: events (customer messages, delivery updates). Each is signed
 * with the business's own app secret (X-Hub-Signature-256) and rejected otherwise. Accepted events are
 * queued for WhatsAppInboundProcessor, which stores customer messages and delivery updates.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));

        if ($mode !== 'subscribe' || $token === '' || ! WhatsAppConnection::query()->where('webhook_verify_token', $token)->exists()) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $payload = $request->getContent();
        $phoneNumberId = (string) data_get($request->json()->all(), 'entry.0.changes.0.value.metadata.phone_number_id', '');
        $connection = $phoneNumberId === '' ? null : WhatsAppConnection::query()->where('phone_number_id', $phoneNumberId)->first();

        if ($connection === null || ! $this->signedBy($connection, $payload, (string) $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature', 401);
        }

        if (strlen($payload) > 1024 * 1024) {
            return response('Payload too large', 413);
        }

        ProcessWhatsAppWebhookJob::dispatch($connection->id, $request->json()->all());
        Log::info('WhatsApp webhook received.', ['organization_id' => $connection->organization_id, 'field' => data_get($request->json()->all(), 'entry.0.changes.0.field')]);

        return response('OK', 200);
    }

    private function signedBy(WhatsAppConnection $connection, string $payload, string $header): bool
    {
        if (! str_starts_with($header, 'sha256=') || blank($connection->app_secret)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, (string) $connection->app_secret), substr($header, 7));
    }
}
