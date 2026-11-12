<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\LeadAds\LeadAdsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * QuoteFlow's Meta app's Page webhook (field "leadgen"): Meta says a lead form was submitted, with the lead's
 * ID only. Signed with QuoteFlow's app secret (X-Hub-Signature-256); the lead's answers are fetched later by
 * ImportLeadAdJob with the Page's own token.
 */
class MetaLeadsWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.whatsapp.embedded.verify_token');
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, LeadAdsService $leadAds): Response
    {
        $payload = $request->getContent();

        // Size first, so an oversized body is refused before anything else is done with it.
        if (strlen($payload) > 1024 * 1024) {
            return response('Payload too large', 413);
        }

        $secret = (string) config('services.whatsapp.embedded.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! str_starts_with($header, 'sha256=') || ! hash_equals(hash_hmac('sha256', $payload, $secret), substr($header, 7))) {
            return response('Invalid signature', 401);
        }

        $received = 0;

        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);

                if (($change['field'] ?? null) !== 'leadgen' || ! isset($value['leadgen_id'], $value['page_id'])) {
                    continue;
                }

                $lead = $leadAds->receive((string) $value['page_id'], (string) $value['leadgen_id'],
                    isset($value['form_id']) ? (string) $value['form_id'] : null, isset($value['ad_id']) ? (string) $value['ad_id'] : null);
                $received += $lead !== null ? 1 : 0;
            }
        }

        Log::info('Lead Ads webhook received.', ['leads' => $received]);

        return response('OK', 200);
    }
}
