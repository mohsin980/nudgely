<?php

use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailJob;
use App\Models\Automation;
use App\Support\Database\TenantIntegrity;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\FollowUps\FollowUpService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest tests (closure style) run on the application's TestCase with a fresh
| database per test. Older class-based PHPUnit tests keep their own setup.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature/FollowUps', 'Feature/Dashboard', 'Feature/Workspace', 'Feature/Estimates', 'Feature/Automations', 'Feature/Team', 'Feature/Billing', 'Feature/Onboarding', 'Feature/Security', 'Feature/Reliability', 'Feature/Performance', 'Feature/Observability');

/*
|--------------------------------------------------------------------------
| Follow-up helpers
|--------------------------------------------------------------------------
*/

/**
 * Dallas HVAC with an admin, customer John Smith and an open estimate conversation.
 *
 * @return array{0: User, 1: Customer, 2: Conversation}
 */
function followUpBusiness(string $name = 'Dallas HVAC'): array
{
    $admin = User::factory()->admin()->create(['name' => 'Mohsin']);
    $admin->organization->update(['name' => $name]);
    $customer = Customer::factory()->for($admin->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
    $conversation = Conversation::factory()->for($customer)->create([
        'organization_id' => $admin->organization_id,
        'subject' => 'Your HVAC estimate',
        'last_message_at' => now(),
    ]);

    return [$admin, $customer, $conversation];
}

/**
 * Automatic follow-up emails on, approval off, verified sender sales@example.com.
 */
function allowAutomaticFollowUpEmail(Organization $organization): void
{
    $organization->forceFill(['automatic_email_enabled' => true, 'require_approval_for_email' => false])->save();

    if (! $organization->emailConnections()->exists()) {
        EmailConnection::factory()->verified()->default()->create([
            'organization_id' => $organization->id,
            'domain' => 'example.com',
            'sender_email' => 'sales@example.com',
            'sender_name' => $organization->name,
        ]);
    }
}

/**
 * An active automation and a pending automated follow-up from it.
 */
function automatedFollowUp(Conversation $conversation, array $attributes = []): FollowUp
{
    $automation = Automation::factory()->active()->create([
        'organization_id' => $conversation->organization_id,
        'name' => 'Interested Customer Follow-Up',
        'trigger_type' => 'customer_reply_classified',
    ]);

    [$followUp] = app(FollowUpService::class)->scheduleAutomated(
        $automation,
        $conversation,
        now()->addDays(2),
        'Following up on your estimate',
        "Hi {{customer.first_name}},\n\nJust checking in to see if you had any questions about your estimate.\n\nBest,\n{{business.name}}",
        null,
    );

    if ($attributes !== []) {
        $followUp->forceFill($attributes)->save();
    }

    return $followUp;
}

/**
 * Store a reply from the conversation's customer.
 */
function customerReply(Conversation $conversation, string $text = 'Yes, please send me the details.'): Message
{
    $message = new Message;
    $message->forceFill([
        'organization_id' => $conversation->organization_id,
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Inbound,
        'channel' => 'email',
        'provider' => 'postmark',
        'from_address' => $conversation->customer->email,
        'to_address' => 'reply+'.str_repeat('c', 40).'@inbound.quoteflow.ai',
        'subject' => 'Re: '.$conversation->subject,
        'body_text' => $text,
        'provider_message_id' => fake()->uuid(),
        'status' => MessageStatus::Received,
        'received_at' => now(),
    ])->save();

    return $message;
}

/**
 * Let the follow-up become due: travel past its due time and run the scheduler step.
 */
function makeDue(FollowUp $followUp): void
{
    test()->travelTo($followUp->refresh()->due_at->addMinute());
    app(FollowUpProcessor::class)->markDue();
}

/*
|--------------------------------------------------------------------------
| Estimate helpers
|--------------------------------------------------------------------------
*/

/**
 * Replace the email provider with the in-memory fake (no HTTP) and return it.
 */
function fakeEmailProvider(): FakeEmailProvider
{
    $provider = new FakeEmailProvider;
    app(EmailProviderManager::class)->extend('postmark', fn () => $provider);

    return $provider;
}

/**
 * Dallas HVAC with a verified sender (sales@example.com), John Smith and a conversation.
 *
 * @return array{0: User, 1: Customer, 2: Conversation}
 */
function estimateBusiness(string $name = 'Dallas HVAC'): array
{
    [$admin, $customer, $conversation] = followUpBusiness($name);
    $admin->organization->forceFill(['automations_enabled' => true])->save();
    EmailConnection::factory()->verified()->default()->create([
        'organization_id' => $admin->organization_id,
        'domain' => 'example.com',
        'sender_email' => 'sales@example.com',
        'sender_name' => $name,
    ]);

    return [$admin, $customer, $conversation];
}

/**
 * Form input for an estimate: AC Installation 1 × $2,500 and Thermostat 1 × $250 by default.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function estimateInput(Customer $customer, array $overrides = []): array
{
    return $overrides + [
        'customer_id' => (string) $customer->id,
        'conversation_id' => '',
        'title' => 'AC Installation',
        'notes' => '',
        'valid_until' => now()->addDays(30)->toDateString(),
        'discount_type' => '',
        'discount_value' => '',
        'tax_rate' => '',
        'items' => [
            ['description' => 'AC Installation', 'quantity' => '1', 'unit_price' => '2500'],
            ['description' => 'Thermostat', 'quantity' => '1', 'unit_price' => '250'],
        ],
    ];
}

/**
 * A draft created through EstimateService.
 *
 * @param  array<string, mixed>  $overrides
 */
function draftEstimate(User $actor, Customer $customer, array $overrides = []): Estimate
{
    return app(EstimateService::class)->create($actor, estimateInput($customer, $overrides));
}

/**
 * A draft that was sent and delivered (fake provider; the sync queue delivers right away).
 *
 * @param  array<string, mixed>  $overrides
 */
function sentEstimate(User $actor, Customer $customer, array $overrides = []): Estimate
{
    $estimate = draftEstimate($actor, $customer, $overrides);
    app(EstimateService::class)->send($actor, $estimate);

    return $estimate->refresh();
}

/*
|--------------------------------------------------------------------------
| Team helpers
|--------------------------------------------------------------------------
*/

/**
 * Dallas HVAC (America/Chicago, USD) with owner John Smith, manager Sarah Wilson, staff Mike Johnson,
 * a verified sender (hello@dallashvac.com) and one customer.
 *
 * @return array{owner: User, manager: User, staff: User, organization: Organization, customer: Customer}
 */
function teamBusiness(string $name = 'Dallas HVAC'): array
{
    $owner = User::factory()->owner()->create(['name' => 'John Smith', 'email' => strtolower(str_replace(' ', '', $name)).'-john@example.com']);
    $organization = $owner->organization;
    $organization->forceFill(['name' => $name, 'timezone' => 'America/Chicago', 'automations_enabled' => true])->save();
    $manager = User::factory()->manager()->for($organization)->create(['name' => 'Sarah Wilson']);
    $staff = User::factory()->staff()->for($organization)->create(['name' => 'Mike Johnson']);
    EmailConnection::factory()->verified()->default()->create([
        'organization_id' => $organization->id, 'domain' => 'dallashvac.com', 'sender_email' => 'hello@dallashvac.com', 'sender_name' => $name,
    ]);
    $customer = Customer::factory()->for($organization)->create(['name' => 'Pat Customer', 'email' => 'pat@example.com']);

    return ['owner' => $owner, 'manager' => $manager, 'staff' => $staff, 'organization' => $organization->refresh(), 'customer' => $customer];
}

/**
 * Small Free-plan limits so limit tests stay fast, with enforcement switched on.
 *
 * @param  array<string, int>  $limits
 */
function tightFreePlan(array $limits): void
{
    foreach ($limits as $key => $value) {
        config(["billing.plans.free.limits.{$key}" => $value]);
    }

    config(['billing.enforce_limits' => true]);
    app()->forgetInstance(PlanCatalog::class);
}

function moveToPlan(Organization $organization, string $plan): Subscription
{
    return app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_'.uniqid(), $plan, SubscriptionStatus::Active,
        currentPeriodStart: CarbonImmutable::now(), currentPeriodEnd: CarbonImmutable::now()->addMonth()));
}

/**
 * POST a signed Stripe webhook to the app.
 */
function webhook(array $event, ?string $secret = 'whsec_test_secret', ?int $timestamp = null): TestResponse
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = $secret === null ? 'garbage' : "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', route('webhooks.stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
}

function stripeEvent(string $type, array $object, ?string $id = null): array
{
    return ['id' => $id ?? 'evt_'.uniqid(), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
}

/**
 * Index names on the given tables. Works on PostgreSQL and MySQL, unlike querying a system catalog.
 *
 * @param  array<int, string>  $tables
 * @return Illuminate\Support\Collection<int, string>
 */
function indexNamesOf(array $tables): Illuminate\Support\Collection
{
    return collect($tables)->flatMap(fn (string $table) => collect(Schema::getIndexes($table))->pluck('name'));
}

/**
 * Build deliberately inconsistent data (a record pointing at another organization's record) to prove the
 * application's own checks still hold even when the database's tenant-integrity triggers are bypassed.
 */
function withoutTenantTriggers(string $table, Closure $build): mixed
{
    // PostgreSQL can disable a table's triggers; MySQL cannot, so its checks are dropped and recreated.
    if (DB::getDriverName() !== 'pgsql') {
        TenantIntegrity::removeFor($table);

        try {
            return $build();
        } finally {
            TenantIntegrity::installFor($table);
        }
    }

    DB::unprepared("ALTER TABLE {$table} DISABLE TRIGGER USER");

    try {
        return $build();
    } finally {
        DB::unprepared("ALTER TABLE {$table} ENABLE TRIGGER USER");
    }
}

/*
|--------------------------------------------------------------------------
| Email and webhook reliability helpers
|--------------------------------------------------------------------------
*/

/**
 * Known webhook credentials, and the fake provider, for the email reliability tests.
 */
function configureEmailWebhooks(): void
{
    config([
        'email.providers.postmark.inbound_webhook_username' => 'postmark',
        'email.providers.postmark.inbound_webhook_secret' => 'inbound-secret',
        'email.providers.postmark.events_webhook_username' => 'postmark',
        'email.providers.postmark.events_webhook_secret' => 'events-secret',
        'email.inbound.reply_domain' => 'inbound.quoteflow.ai',
    ]);
}

/**
 * An email that went through the real path (queue, job, provider) and was accepted: status sent,
 * provider message ID stored. Returns the message.
 */
function sentEmail(array $business, string $subject = 'Your estimate'): Message
{
    $conversation = Conversation::factory()->for($business['customer'])->create([
        'organization_id' => $business['organization']->id,
        'subject' => $subject,
    ]);
    $message = app(EmailService::class)->sendToConversation($conversation, $subject, '<p>Hi Pat</p>', 'Hi Pat');
    (new SendEmailJob($message->id))->handle(app(EmailService::class));

    return $message->refresh();
}

/**
 * POST a Postmark delivery event with the events credentials (or whatever the caller passes).
 */
function postDeliveryEvent(array $payload, ?string $user = 'postmark', ?string $secret = 'events-secret'): TestResponse
{
    $headers = $user === null ? [] : ['Authorization' => 'Basic '.base64_encode("{$user}:{$secret}")];

    return test()->postJson(route('webhooks.email.events', ['provider' => 'postmark']), $payload, $headers);
}

/**
 * A Postmark delivery report for a message, shaped like the provider's payload.
 */
function deliveryReport(string $recordType, string $messageId, string $recipient, array $extra = []): array
{
    return array_merge([
        'RecordType' => $recordType,
        'MessageID' => $messageId,
        'Recipient' => $recipient,
        'Email' => $recipient,
        'MessageStream' => 'outbound',
    ], $extra);
}

/**
 * Collect every log call (level, message, context) made while the callback runs.
 *
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function captureLogs(Closure $callback): array
{
    $captured = [];

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::shouldReceive($level)->andReturnUsing(function (...$args) use (&$captured, $level) {
            $captured[] = ['level' => $level, 'message' => (string) ($args[0] ?? ''), 'context' => $args[1] ?? []];
        });
    }

    // Log::log($level, $message, $context) carries the level first.
    Log::shouldReceive('log')->andReturnUsing(function (...$args) use (&$captured) {
        $captured[] = ['level' => (string) ($args[0] ?? ''), 'message' => (string) ($args[1] ?? ''), 'context' => $args[2] ?? []];
    });

    $callback();

    return $captured;
}
