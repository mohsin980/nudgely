<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\ObservabilityProbeJob;

beforeEach(function () {
    $this->withoutVite();
    ObservabilityProbeJob::$seen = [];

    // Routes that exist only for these tests.
    Route::middleware('web')->get('/__observability/boom', function () {
        throw new RuntimeException('Database rejected password=hunter2 for jane@acme.com');
    });
    Route::middleware('web')->get('/__observability/log', function () {
        Log::channel('single')->info('observability probe', ['marker' => request()->query('marker')]);

        return response()->json(['ok' => true]);
    });
    Route::middleware('web')->get('/__observability/dispatch', function () {
        ObservabilityProbeJob::dispatch('ok');

        return response()->json(['queued' => true]);
    });
});

/**
 * Captured log records, written by the code under test during $callback.
 *
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function logsDuring(Closure $callback): array
{
    return captureLogs($callback);
}

function recordsNamed(array $logs, string $message): array
{
    return array_values(array_filter($logs, fn ($log) => $log['message'] === $message));
}

// ─── Correlation IDs ───────────────────────────────────────────────────────────────────────

test('a request without a correlation ID gets a fresh one, returned in the header', function () {
    $response = $this->getJson('/health/ready');

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

test('a valid incoming request ID is kept, so a caller can trace its own request', function () {
    $this->getJson('/health/ready', ['X-Request-Id' => 'req-abc12345'])
        ->assertHeader('X-Request-Id', 'req-abc12345');
});

test('an unsafe or oversized incoming request ID is replaced, never echoed', function (string $incoming) {
    $id = $this->getJson('/health/ready', ['X-Request-Id' => $incoming])->headers->get('X-Request-Id');

    expect($id)->not->toBe($incoming)->toMatch('/^[0-9a-f-]{36}$/');
})->with([
    'too short' => ['abc'],
    'too long' => [str_repeat('a', 65)],
    'log injection' => ["bad\nid-with-newline"],
    'spaces' => ['has spaces in it'],
]);

test('the correlation ID appears in the log file written during the request', function () {
    $marker = 'marker-'.bin2hex(random_bytes(6));

    $this->getJson("/__observability/log?marker={$marker}", ['X-Request-Id' => 'req-probe12345'])->assertOk();

    $log = file_get_contents(storage_path('logs/laravel.log'));
    $line = collect(explode("\n", $log))->first(fn ($l) => str_contains($l, $marker));

    expect($line)->not->toBeNull()
        ->and($line)->toContain('req-probe12345');
});

test('a job dispatched by a request runs under the same correlation ID', function () {
    $this->getJson('/__observability/dispatch', ['X-Request-Id' => 'req-dispatch98'])->assertOk();

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect(ObservabilityProbeJob::$seen)->toBe(['req-dispatch98']);
});

// ─── Error responses ──────────────────────────────────────────────────────────────────────

test('an unexpected exception returns a safe JSON 500 with a reference, and no trace or message', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/__observability/boom', ['X-Request-Id' => 'req-boom12345']);

    $response->assertStatus(500)->assertJson(['reference' => 'req-boom12345']);
    expect($response->getContent())->not->toContain('hunter2')->not->toContain('jane@acme.com')
        ->not->toContain('RuntimeException')->not->toContain('#0');
});

test('an unexpected exception on a web page shows the reference, not the error', function () {
    config(['app.debug' => false]);

    $response = $this->get('/__observability/boom', ['X-Request-Id' => 'req-page12345']);

    $response->assertStatus(500);
    expect($response->getContent())->toContain('req-page12345')->not->toContain('hunter2')->not->toContain('RuntimeException');
});

test('an unexpected exception is logged once, scrubbed, with the route name and no URL', function () {
    config(['app.debug' => false]);

    $logs = logsDuring(fn () => $this->getJson('/__observability/boom'));

    $unhandled = recordsNamed($logs, 'Unhandled exception.');
    expect($unhandled)->toHaveCount(1)
        ->and($unhandled[0]['context']['exception'])->toBe(RuntimeException::class)
        ->and(json_encode($logs))->not->toContain('hunter2')->not->toContain('jane@acme.com');
});

test('validation errors keep their normal 422 response', function () {
    $this->postJson('/login', [])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

test('missing routes and denied actions keep their status codes, and are not logged as incidents', function () {
    config(['app.debug' => false]);
    $logs = logsDuring(fn () => $this->getJson('/no-such-route-here'));

    $this->getJson('/no-such-route-here')->assertNotFound();
    expect(recordsNamed($logs, 'Unhandled exception.'))->toBeEmpty();
});

test('an authorization denial stays a 403, not a server error', function () {
    ['staff' => $staff] = teamBusiness('Denied HVAC');

    $this->actingAs($staff)->getJson('/settings/team')->assertForbidden();
});

// ─── Queue lifecycle ──────────────────────────────────────────────────────────────────────

test('a successful job logs its start and completion with a duration', function () {
    $this->getJson('/__observability/dispatch');

    $logs = logsDuring(fn () => $this->artisan('queue:work', ['connection' => 'database', '--once' => true]));

    expect(recordsNamed($logs, 'Job started.'))->toHaveCount(1)
        ->and(recordsNamed($logs, 'Job completed.'))->toHaveCount(1)
        ->and(recordsNamed($logs, 'Job completed.')[0]['context']['duration_ms'])->toBeFloat()
        ->and(recordsNamed($logs, 'Job started.')[0]['level'])->toBe('debug');
});

test('a job that fails and will be retried is a warning with the exception class and a scrubbed message', function () {
    ObservabilityProbeJob::dispatch('fail', 2)->onConnection('database');

    $logs = logsDuring(fn () => $this->artisan('queue:work', ['connection' => 'database', '--once' => true]));

    $retry = recordsNamed($logs, 'Job attempt failed; it will be retried.');
    expect($retry)->toHaveCount(1)
        ->and($retry[0]['level'])->toBe('warning')
        ->and($retry[0]['context']['retryable'])->toBeTrue()
        ->and($retry[0]['context']['exception']['exception'])->toBe(RuntimeException::class)
        ->and(json_encode($logs))->not->toContain('jane@acme.com')->not->toContain('abc123def456')
        ->and(recordsNamed($logs, 'Job failed permanently.'))->toBeEmpty();
});

test('a job that fails for good is one error record, and is not logged a second time as an unhandled exception', function () {
    ObservabilityProbeJob::dispatch('fail', 1)->onConnection('database');

    $logs = logsDuring(fn () => $this->artisan('queue:work', ['connection' => 'database', '--once' => true]));

    $failed = recordsNamed($logs, 'Job failed permanently.');
    expect($failed)->toHaveCount(1)
        ->and($failed[0]['level'])->toBe('error')
        ->and($failed[0]['context']['retryable'])->toBeFalse()
        ->and($failed[0]['context']['job'])->toBe(ObservabilityProbeJob::class)
        ->and(recordsNamed($logs, 'Unhandled exception.'))->toBeEmpty()
        ->and(json_encode($logs))->not->toContain('jane@acme.com');
});

// ─── Readiness ────────────────────────────────────────────────────────────────────────────

test('readiness returns only a status, and is not cached', function () {
    $response = $this->getJson('/health/ready');

    $response->assertOk()->assertExactJson(['status' => 'ok']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('readiness reports unavailable when the database cannot be reached, without saying why', function () {
    $original = config('database.connections.pgsql.port');
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    try {
        $response = null;
        $logs = logsDuring(function () use (&$response) {
            $response = $this->getJson('/health/ready');
        });
        expect($response->status())->toBe(503)
            ->and($response->json())->toBe(['status' => 'unavailable']);
        expect(json_encode($response->json()))->not->toContain('127.0.0.1')->not->toContain('port');
        expect(recordsNamed($logs, 'Readiness check failed: database.')[0]['context']['event'])->toBe('health.database_unavailable');
    } finally {
        config(['database.connections.pgsql.port' => $original]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
});

test('the liveness route still answers without touching the database', function () {
    $this->get('/up')->assertOk();
});

test('a repeated Stripe event is logged as a duplicate, with its event type and ID and nothing else', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    ['organization' => $organization] = teamBusiness('Stripe Log HVAC');
    $event = stripeEvent('customer.subscription.updated', ['id' => 'sub_test_1', 'customer' => 'cus_unknown'], 'evt_observe_1');

    webhook($event)->assertOk();
    $logs = logsDuring(fn () => webhook($event)->assertOk()->assertJson(['duplicate' => true]));

    $duplicate = recordsNamed($logs, 'Stripe webhook duplicate ignored.');
    expect($duplicate)->toHaveCount(1)
        ->and($duplicate[0]['context'])->toBe(['event' => 'billing.webhook.duplicate', 'event_type' => 'customer.subscription.updated', 'provider_event_id' => 'evt_observe_1'])
        ->and(json_encode($logs))->not->toContain('cus_unknown')->not->toContain('whsec_test_secret');
});

test('a bad Stripe signature is rejected and logged without the signature or the body', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    $event = stripeEvent('invoice.paid', ['id' => 'in_1'], 'evt_observe_2');

    $logs = logsDuring(fn () => webhook($event, 'whsec_wrong')->assertStatus(400));

    $rejected = recordsNamed($logs, 'Stripe webhook signature verification failed.');
    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]['context']['event'])->toBe('billing.webhook.rejected')
        ->and(json_encode($logs))->not->toContain('whsec_wrong')->not->toContain('in_1')->not->toContain('t=');
});
