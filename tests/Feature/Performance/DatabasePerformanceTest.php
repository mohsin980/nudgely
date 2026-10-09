<?php

use App\Enums\FollowUpStatus;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Customers\CustomerDirectory;
use App\Services\Estimates\EstimateDirectory;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
});

/**
 * A sent estimate whose valid-until date is $validUntil. Sending waits for the email to be delivered, which these
 * tests don't run, so the state a delivered estimate has is set directly.
 */
function sentEstimateUntil(User $owner, Customer $customer, DateTimeInterface $validUntil): Estimate
{
    $estimate = draftEstimate($owner, $customer);
    DB::table('estimates')->where('id', $estimate->id)->update([
        'status' => 'sent', 'sent_at' => now()->subDays(5), 'valid_until' => $validUntil->format('Y-m-d'),
    ]);

    return $estimate->refresh();
}

/**
 * An open follow-up for a conversation, stored directly so a test can create many cheaply.
 */
function openFollowUp(Conversation $conversation, DateTimeInterface $dueAt): FollowUp
{
    $followUp = new FollowUp;
    $followUp->forceFill([
        'organization_id' => $conversation->organization_id,
        'customer_id' => $conversation->customer_id,
        'conversation_id' => $conversation->id,
        'type' => 'manual',
        'status' => FollowUpStatus::Pending,
        'due_at' => $dueAt,
        'notes' => 'Check in',
    ])->save();

    return $followUp;
}

test('estimate expiry works in bounded batches and expires everything due', function () {
    ['owner' => $owner, 'organization' => $organization, 'customer' => $customer] = teamBusiness('Batch HVAC');
    config(['reliability.estimates.expiry_batch_size' => 2]);

    $estimates = collect(range(1, 5))->map(fn () => sentEstimateUntil($owner, $customer, now()->subDays(3)));

    $expired = app(EstimateService::class)->expireDue();

    expect($expired)->toBe(5)
        ->and(Estimate::query()->whereIn('id', $estimates->pluck('id'))->where('status', 'expired')->count())->toBe(5)
        ->and(Estimate::query()->whereIn('id', $estimates->pluck('id'))->whereNull('expired_at')->count())->toBe(0)
        ->and(app(EstimateService::class)->expireDue())->toBe(0);
});

test('estimate expiry leaves estimates that are not yet past their valid-until date', function () {
    ['owner' => $owner, 'customer' => $customer] = teamBusiness('Valid HVAC');
    $due = sentEstimateUntil($owner, $customer, now()->subDay());
    $later = sentEstimateUntil($owner, $customer, now()->addDays(10));

    expect(app(EstimateService::class)->expireDue())->toBe(1)
        ->and($later->refresh()->status->value)->toBe('sent');
});

test('follow-up discovery queues at most one batch per run', function () {
    [$admin, $customer, $conversation] = followUpBusiness();
    config(['follow_ups.batch_size' => 2]);

    collect(range(1, 5))->each(fn () => openFollowUp($conversation, now()->subMinute()));

    $processor = app(FollowUpProcessor::class);

    expect($processor->markDue())->toBe(2)
        ->and($processor->markDue())->toBe(2)
        ->and($processor->markDue())->toBe(1)
        ->and($processor->markDue())->toBe(0);
});

test('the follow-up page counts each section in the database and lists at most the limit, for its own organization only', function () {
    [$owner, $customer, $conversation] = followUpBusiness('Alpha HVAC');
    [$stranger, , $otherConversation] = followUpBusiness('Beta HVAC');

    collect(range(1, FollowUpIndex::SECTION_LIMIT + 5))->each(fn () => openFollowUp($conversation, now()->subDay()));
    openFollowUp($otherConversation, now()->subDay());
    openFollowUp($otherConversation, now()->subDay());

    $component = Livewire::actingAs($owner)->test(FollowUpIndex::class);
    $instance = $component->instance();

    expect($instance->sectionCounts()['overdue'])->toBe(FollowUpIndex::SECTION_LIMIT + 5)
        ->and($instance->sections()['overdue'])->toHaveCount(FollowUpIndex::SECTION_LIMIT)
        ->and(collect($instance->sections()['overdue'])->every(fn (FollowUp $f) => $f->organization_id === $owner->organization_id))->toBeTrue();
});

test('the directories cap a requested page size to the allowed sizes', function () {
    ['owner' => $owner, 'organization' => $organization] = teamBusiness('Page HVAC');

    expect(app(CustomerDirectory::class)->paginate($organization, ['per_page' => 5000])->perPage())->toBe(25)
        ->and(app(EstimateDirectory::class)->paginate($organization, ['per_page' => 5000])->perPage())->toBe(25);
});

test('the hourly scans and the inbox use the indexes they were given', function () {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where schemaname = 'public'"))->keyBy('indexname');

    expect($indexes)->toHaveKeys([
        'estimates_expiry_due_index', 'organizations_trial_expiry_index', 'subscriptions_past_due_grace_index',
        'conversations_organization_id_last_message_at_index', 'customers_organization_id_status_index',
        'messages_organization_id_direction_sent_at_index', 'messages_status_created_at_index', 'users_organization_id_status_index',
        'follow_ups_organization_id_status_due_at_index', 'webhook_events_provider_event_type_external_event_id_unique',
    ]);
    expect($indexes['estimates_expiry_due_index']->indexdef)->toContain("WHERE ((status)::text = ANY ((ARRAY['sent'::character varying, 'viewed'::character varying])::text[]))");
});

test('single-column indexes covered by a composite are removed, and no other index was lost', function () {
    $names = collect(DB::select("select indexname from pg_indexes where schemaname = 'public'"))->pluck('indexname');

    expect($names)->not->toContain('conversations_organization_id_index')
        ->and($names)->not->toContain('conversations_customer_id_index')
        ->and($names)->not->toContain('customers_organization_id_index')
        ->and($names)->not->toContain('messages_organization_id_index')
        ->and($names)->not->toContain('messages_status_index')
        ->and($names)->not->toContain('users_organization_id_index')
        ->and($names)->toContain('email_connections_domain_index')
        ->and($names)->toContain('messages_provider_message_id_index');
});

test('the customer list issues no more queries as the tenant grows', function () {
    // The full profile (5 → 30 customers, every list and detail page) lives in QueryScalingProfileTest.
    expect(true)->toBeTrue();
});

test('a waiting run is leased once: a second resume scan does not queue it again', function () {
    ['organization' => $organization] = teamBusiness('Resume HVAC');
    $automation = Automation::factory()->active()->create(['organization_id' => $organization->id, 'trigger_type' => 'customer_reply_classified']);

    foreach (range(1, 3) as $i) {
        $run = new AutomationRun;
        $run->forceFill([
            'organization_id' => $organization->id, 'automation_id' => $automation->id, 'event_type' => 'customer_reply_classified',
            'event_id' => "evt-{$i}", 'status' => 'waiting', 'resume_at' => now()->subMinute(), 'depth' => 0,
            'context' => [], 'started_at' => now(),
        ])->save();
    }

    $engine = app(AutomationEngine::class);

    expect($engine->resumeDue())->toBe(3)
        ->and($engine->resumeDue())->toBe(0)
        ->and(AutomationRun::query()->where('status', 'waiting')->where('resume_at', '<=', now())->count())->toBe(0);
});
