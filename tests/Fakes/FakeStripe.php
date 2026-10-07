<?php

namespace Tests\Fakes;

use App\Billing\PlanCatalog;
use App\Services\Billing\BillingProviderManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory Stripe API behind Http::fake(): customers, checkout sessions, subscriptions,
 * subscription schedules and billing portal sessions. Records every request and can fail on demand.
 */
class FakeStripe
{
    public const BASE = 'https://api.stripe.test/v1';

    /** @var list<array{method: string, path: string, data: array<string, mixed>, headers: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    public array $customers = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<string, array<string, mixed>> */
    public array $schedules = [];

    private int $next = 1;

    /** @var array{status: int, type: string}|'connection'|null */
    private array|string|null $failure = null;

    public static function install(): self
    {
        $fake = new self;
        config(['services.stripe.secret' => 'sk_test_fake_secret', 'services.stripe.api_base' => self::BASE, 'billing.provider' => 'stripe',
            'billing.plans.starter.provider_price_id' => 'price_starter', 'billing.plans.pro.provider_price_id' => 'price_pro']);
        app()->forgetInstance(PlanCatalog::class);
        app()->forgetInstance(BillingProviderManager::class);
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function failWith(int $status = 500, string $type = 'api_error'): void
    {
        $this->failure = ['status' => $status, 'type' => $type];
    }

    public function failToConnect(): void
    {
        $this->failure = 'connection';
    }

    public function recover(): void
    {
        $this->failure = null;
    }

    /**
     * The customer pays on the hosted page: the session completes and a subscription exists.
     */
    public function completeCheckout(string $sessionId): array
    {
        $session = &$this->sessions[$sessionId];
        $trialDays = (int) ($session['subscription_data']['trial_period_days'] ?? 0);
        $now = time();
        $trialEnd = $trialDays > 0 ? $now + $trialDays * 86400 : null;
        $id = 'sub_'.$this->next++;

        $this->subscriptions[$id] = [
            'id' => $id, 'object' => 'subscription', 'customer' => $session['customer'],
            'status' => $trialEnd ? 'trialing' : 'active', 'trial_end' => $trialEnd,
            'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null, 'schedule' => null,
            'metadata' => $session['subscription_data']['metadata'] ?? [],
            'items' => ['data' => [['id' => 'si_'.$this->next++, 'price' => ['id' => $session['line_items'][0]['price']],
                'current_period_start' => $now, 'current_period_end' => $trialEnd ?? $now + 30 * 86400]]],
        ];
        $session['status'] = 'complete';
        $session['subscription'] = $id;

        return $this->subscriptions[$id];
    }

    /**
     * Time passes at Stripe: the period ends and a scheduled phase takes over.
     */
    public function advancePastPeriodEnd(string $subscriptionId): void
    {
        $sub = &$this->subscriptions[$subscriptionId];
        $item = &$sub['items']['data'][0];
        $end = $item['current_period_end'];

        if ($sub['cancel_at_period_end']) {
            $sub['status'] = 'canceled';
            $sub['ended_at'] = $end;

            return;
        }

        if ($sub['schedule'] !== null) {
            $phases = $this->schedules[$sub['schedule']]['phases'];
            $item['price']['id'] = $phases[1]['items'][0]['price'];
            unset($this->schedules[$sub['schedule']]);
            $sub['schedule'] = null;
        }

        $sub['status'] = $sub['status'] === 'trialing' ? 'active' : $sub['status'];
        $item['current_period_start'] = $end;
        $item['current_period_end'] = $end + 30 * 86400;
    }

    /**
     * @return list<array{method: string, path: string, data: array<string, mixed>, headers: array<string, mixed>}>
     */
    public function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, fn ($r) => $r['method'] === strtoupper($method) && str_starts_with($r['path'], $pathPrefix)));
    }

    public function handle(Request $request)
    {
        $path = ltrim(substr(strtok($request->url(), '?'), strlen(self::BASE)), '/');
        $method = $request->method();
        $data = $method === 'GET' ? $this->query($request->url()) : $request->data();
        $this->requests[] = ['method' => $method, 'path' => $path, 'data' => $data, 'headers' => $request->headers()];

        if ($this->failure === 'connection') {
            throw new ConnectionException('Connection timed out');
        }

        if ($this->failure !== null) {
            return Http::response(['error' => ['type' => $this->failure['type'], 'message' => 'Internal error req_secret_detail']], $this->failure['status']);
        }

        if (($request->header('Authorization')[0] ?? '') !== 'Bearer sk_test_fake_secret') {
            return Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_invalid']], 401);
        }

        $segments = explode('/', $path);

        return match (true) {
            $method === 'POST' && $path === 'customers' => $this->ok($this->customers[$id = 'cus_'.$this->next++] = ['id' => $id] + $data),
            $method === 'POST' && $path === 'checkout/sessions' => $this->ok($this->sessions[$id = 'cs_test_'.$this->next++] = ['id' => $id, 'url' => "https://checkout.stripe.test/c/{$id}", 'status' => 'open', 'subscription' => null] + $data),
            $method === 'GET' && $segments[0] === 'checkout' => $this->sessionResponse($segments[2]),
            $method === 'GET' && $segments[0] === 'subscriptions' => $this->ok($this->expanded($segments[1])),
            $method === 'POST' && $segments[0] === 'subscriptions' => $this->updateSubscription($segments[1], $data),
            $method === 'DELETE' && $segments[0] === 'subscriptions' => $this->deleteSubscription($segments[1]),
            $method === 'POST' && $path === 'subscription_schedules' => $this->createSchedule($data['from_subscription']),
            $method === 'POST' && $segments[0] === 'subscription_schedules' && ($segments[2] ?? null) === 'release' => $this->releaseSchedule($segments[1]),
            $method === 'POST' && $segments[0] === 'subscription_schedules' => $this->updateSchedule($segments[1], $data),
            $method === 'POST' && $path === 'billing_portal/sessions' => $this->ok(['id' => 'bps_1', 'url' => 'https://billing.stripe.test/p/session_1', 'customer' => $data['customer'], 'return_url' => $data['return_url']]),
            default => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => "Unknown {$method} {$path}"]], 404),
        };
    }

    private function sessionResponse(string $id)
    {
        $session = $this->sessions[$id] ?? null;

        if ($session === null) {
            return Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']], 404);
        }

        if ($session['subscription'] !== null) {
            $session['subscription'] = $this->expanded($session['subscription']);
        }

        return $this->ok($session);
    }

    private function expanded(string $id): array
    {
        $sub = $this->subscriptions[$id];
        $sub['schedule'] = $sub['schedule'] === null ? null : $this->schedules[$sub['schedule']];

        return $sub;
    }

    private function updateSubscription(string $id, array $data)
    {
        $sub = &$this->subscriptions[$id];

        if (isset($data['items'][0]['price'])) {
            $sub['items']['data'][0]['price']['id'] = $data['items'][0]['price'];
            $sub['last_proration_behavior'] = $data['proration_behavior'] ?? null;
        }

        if (isset($data['cancel_at_period_end'])) {
            $sub['cancel_at_period_end'] = $data['cancel_at_period_end'] === 'true';
            $sub['canceled_at'] = $sub['cancel_at_period_end'] ? time() : null;
        }

        return $this->ok($sub);
    }

    private function deleteSubscription(string $id)
    {
        $sub = &$this->subscriptions[$id];
        $sub['status'] = 'canceled';
        $sub['canceled_at'] = $sub['ended_at'] = time();

        return $this->ok($sub);
    }

    private function createSchedule(string $subscriptionId)
    {
        $sub = &$this->subscriptions[$subscriptionId];
        $item = $sub['items']['data'][0];
        $id = 'sub_sched_'.$this->next++;
        $this->schedules[$id] = ['id' => $id, 'subscription' => $subscriptionId, 'end_behavior' => 'release',
            'phases' => [['items' => [['price' => $item['price']['id']]], 'start_date' => $item['current_period_start'], 'end_date' => $item['current_period_end']]]];
        $sub['schedule'] = $id;

        return $this->ok($this->schedules[$id]);
    }

    private function updateSchedule(string $id, array $data)
    {
        $phases = array_map(fn ($p) => ['items' => [['price' => $p['items'][0]['price']]], 'start_date' => (int) $p['start_date'], 'end_date' => isset($p['end_date']) ? (int) $p['end_date'] : null], $data['phases']);
        $this->schedules[$id]['phases'] = $phases;
        $this->schedules[$id]['end_behavior'] = $data['end_behavior'] ?? 'release';

        return $this->ok($this->schedules[$id]);
    }

    private function releaseSchedule(string $id)
    {
        $schedule = $this->schedules[$id];
        $this->subscriptions[$schedule['subscription']]['schedule'] = null;
        unset($this->schedules[$id]);

        return $this->ok($schedule + ['status' => 'released']);
    }

    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function ok(array $body)
    {
        return Http::response($body, 200);
    }
}
