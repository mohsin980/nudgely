<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Team\NotificationType;
use App\Jobs\Billing\CheckGracePeriodsJob;
use App\Jobs\Billing\ExpireTrialsJob;
use App\Jobs\Billing\NotifyOwnerJob;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\Subscription;
use App\Services\Billing\BillingLifecycle;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    ['owner' => $this->owner, 'organization' => $this->organization] = teamBusiness('Retention Test HVAC');
    Queue::fake();
});

test('a billing notification job retried after success tells the owner once', function () {
    $job = new NotifyOwnerJob($this->organization->id, NotificationType::TrialEnded->value, 'Your trial has ended.', '/settings/billing', 'trial-ended:test');

    $job->handle(app(TeamDirectory::class), app(TeamNotifier::class));
    $job->handle(app(TeamDirectory::class), app(TeamNotifier::class));

    expect(DB::table('notifications')->count())->toBe(1);
});

test('an expired trial is ended once, however many times the scheduled job runs', function () {
    $this->organization->forceFill(['trial_ends_at' => now()->subDay(), 'trial_expired_at' => null])->save();

    (new ExpireTrialsJob)->handle(app(BillingLifecycle::class));
    (new ExpireTrialsJob)->handle(app(BillingLifecycle::class));

    expect(OrganizationActivity::query()->where('organization_id', $this->organization->id)->where('action', 'trial_expired')->count())->toBe(1)
        ->and($this->organization->fresh()->trial_expired_at)->not->toBeNull();
});

test('a past-due subscription is restricted once, however many times the grace check runs', function () {
    moveToPlan($this->organization, 'starter');
    Subscription::query()->where('organization_id', $this->organization->id)->update([
        'status' => SubscriptionStatus::PastDue->value,
        'past_due_since' => now()->subDays(30),
    ]);

    (new CheckGracePeriodsJob)->handle(app(BillingLifecycle::class));
    (new CheckGracePeriodsJob)->handle(app(BillingLifecycle::class));

    expect(OrganizationActivity::query()->where('organization_id', $this->organization->id)->where('action', 'billing_restriction_applied')->count())->toBe(1)
        ->and(Subscription::query()->where('organization_id', $this->organization->id)->value('restricted_at'))->not->toBeNull();
});

test('retention removes processed webhook deliveries past their period and keeps the rest', function () {
    $old = now()->subDays(120);
    DB::table('webhook_events')->insert([
        ['provider' => 'postmark', 'event_type' => 'inbound_email', 'external_event_id' => 'old-done', 'payload' => '{}', 'processed_at' => $old, 'created_at' => $old, 'updated_at' => $old],
        ['provider' => 'postmark', 'event_type' => 'inbound_email', 'external_event_id' => 'recent-done', 'payload' => '{}', 'processed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
        ['provider' => 'postmark', 'event_type' => 'inbound_email', 'external_event_id' => 'old-unprocessed', 'payload' => '{}', 'processed_at' => null, 'created_at' => $old, 'updated_at' => $old],
    ]);

    expect(app(RetentionPruner::class)->prune()['webhook_events'])->toBe(1);

    expect(DB::table('webhook_events')->pluck('external_event_id')->sort()->values()->all())
        ->toBe(['old-unprocessed', 'recent-done']);
});

test('retention removes invitations that expired unused and keeps accepted, active and recent ones', function () {
    $expired = now()->subDays(60);
    $base = ['organization_id' => $this->organization->id, 'invited_by' => $this->owner->id, 'name' => 'Invitee', 'role' => 'staff', 'accepted_at' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()];
    $tokenHash = fn (string $seed) => hash('sha256', $seed);

    DB::table('team_invitations')->insert([
        array_merge($base, ['email' => 'gone@example.com', 'token_hash' => $tokenHash('a'), 'expires_at' => $expired]),
        array_merge($base, ['email' => 'accepted@example.com', 'token_hash' => $tokenHash('b'), 'expires_at' => $expired, 'accepted_at' => now()->subDays(50)]),
        array_merge($base, ['email' => 'active@example.com', 'token_hash' => $tokenHash('c'), 'expires_at' => now()->addDays(3)]),
    ]);

    expect(app(RetentionPruner::class)->prune()['team_invitations'])->toBe(1);

    expect(DB::table('team_invitations')->pluck('email')->sort()->values()->all())
        ->toBe(['accepted@example.com', 'active@example.com']);
});

test('retention never removes customers, estimates, messages or the organization', function () {
    $customers = Customer::query()->where('organization_id', $this->organization->id)->count();
    $messages = Message::query()->count();

    app(RetentionPruner::class)->prune();

    expect(Customer::query()->where('organization_id', $this->organization->id)->count())->toBe($customers)
        ->and(Message::query()->count())->toBe($messages)
        ->and(Organization::whereKey($this->organization->id)->exists())->toBeTrue();
});
