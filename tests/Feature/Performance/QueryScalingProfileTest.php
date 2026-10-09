<?php

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    $this->business = teamBusiness('Scaling HVAC');
    $this->owner = $this->business['owner'];
    $this->organization = $this->business['organization'];
});

/**
 * Give the organization $count more customers, each with a conversation, an inbound reply, an estimate
 * and a pending follow-up: the shape a busy tenant has.
 */
function growTenant(User $owner, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $customer = Customer::factory()->for($owner->organization)->create(['name' => "Customer {$i}", 'email' => "c{$i}-".uniqid().'@example.com']);
        $conversation = Conversation::factory()->for($customer)->create([
            'organization_id' => $owner->organization_id, 'subject' => "Job {$i}", 'last_message_at' => now()->subMinutes($i),
        ]);
        Message::factory()->create([
            'organization_id' => $owner->organization_id, 'conversation_id' => $conversation->id,
            'email_connection_id' => $owner->organization->emailConnections()->default()->value('id'),
            'direction' => 'inbound', 'status' => 'received', 'received_at' => now()->subMinutes($i), 'from_address' => $customer->email,
        ]);
        draftEstimate($owner, $customer);
        automatedFollowUp($conversation, ['due_at' => now()->addDays(2)]);
    }
}

/**
 * Load a page as the owner and report its query count, total time and slowest statement.
 *
 * @return array{queries: int, ms: float, slowest_ms: float, slowest_sql: string}
 */
function measurePage(User $user, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->actingAs($user)->get($url)->assertOk();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Guard against a page that renders nothing: the measurement would then mean nothing.
    $html = $response->getContent();
    expect(str_contains($html, 'Customer') || str_contains($html, 'Job'))->toBeTrue("{$url} rendered no records");

    $slowest = collect($log)->sortByDesc('time')->first();

    return [
        'queries' => count($log),
        'ms' => round(collect($log)->sum('time'), 1),
        'slowest_ms' => round($slowest['time'] ?? 0, 1),
        'slowest_sql' => substr((string) ($slowest['query'] ?? ''), 0, 120),
    ];
}

function detailPagesFor(): array
{
    $customer = Customer::query()->where('organization_id', test()->organization->id)->orderBy('id')->first();
    $conversation = Conversation::query()->where('organization_id', test()->organization->id)->orderBy('id')->first();
    $estimate = Estimate::query()->where('organization_id', test()->organization->id)->orderBy('id')->first();

    return ["/customers/{$customer->id}", "/conversations/{$conversation->id}", "/estimates/{$estimate->id}"];
}

test('list pages do not issue more queries as the tenant grows', function () {
    $pages = ['/customers', '/conversations', '/estimates', '/follow-ups', '/dashboard', '/automations'];

    $small = [];
    growTenant($this->owner, 5);
    $pages = array_merge($pages, detailPagesFor());
    foreach ($pages as $url) {
        $small[$url] = measurePage($this->owner, $url);
    }

    growTenant($this->owner, 25);
    $pages = array_values(array_unique($pages));
    $large = [];
    foreach ($pages as $url) {
        $large[$url] = measurePage($this->owner, $url);
    }

    $report = (bool) getenv('PROFILE_QUERIES');
    $report && fwrite(STDERR, "\n[profile] page: queries(5 customers) -> queries(30 customers), total ms, slowest ms\n");
    foreach ($pages as $url) {
        $report && fwrite(STDERR, sprintf("[profile] %-14s %3d -> %3d   %7.1f ms   slowest %5.1f ms  %s\n",
            $url, $small[$url]['queries'], $large[$url]['queries'], $large[$url]['ms'], $large[$url]['slowest_ms'], $large[$url]['slowest_sql']));
    }

    // A page that grows with its row count has a per-row query: report it.
    foreach ($pages as $url) {
        $growth = $large[$url]['queries'] - $small[$url]['queries'];
        expect($growth)->toBeLessThanOrEqual(0, "{$url} issues ".$growth.' more queries for 25 extra customers');
    }
});
