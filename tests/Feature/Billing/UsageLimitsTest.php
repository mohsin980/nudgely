<?php

use App\Billing\PlanCatalog;
use App\Billing\ProviderSubscription;
use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\Estimates\EstimateException;
use App\Exceptions\Team\TeamActionException;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Automation\AutomationBuilder;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use App\Services\Customers\CustomerService;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use App\Services\Team\InvitationService;
use App\Services\Team\TeamService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    ['owner' => $this->owner, 'manager' => $this->manager, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness();
    $this->usage = app(UsageService::class);
});

function subscribeWithPeriod(Organization $organization, string $plan, string $start, string $end): void
{
    app(BillingService::class)->sync($organization, 'manual', new ProviderSubscription('manual_sub_period_'.$organization->id, $plan, SubscriptionStatus::Active,
        currentPeriodStart: CarbonImmutable::parse($start, 'UTC'), currentPeriodEnd: CarbonImmutable::parse($end, 'UTC')));
}

function outboundMessage(Organization $organization, MessageStatus $status = MessageStatus::Sent, string|CarbonImmutable|null $at = null, MessageDirection $direction = MessageDirection::Outbound): Message
{
    $connection = EmailConnection::where('organization_id', $organization->id)->first() ?? EmailConnection::factory()->verified()->default()->create(['organization_id' => $organization->id]);

    return Message::factory()->create(['email_connection_id' => $connection->id, 'organization_id' => $organization->id, 'direction' => $direction, 'status' => $status,
        'created_at' => $at === null ? now() : CarbonImmutable::parse($at, 'UTC')]);
}

// ─── Usage calculations ──────────────────────────────────────────────────────────────────

describe('usage', function () {
    test('customers: counts non-deleted customers of the organization only', function () {
        Customer::factory()->count(2)->for($this->organization)->create();
        Customer::factory()->for($this->organization)->create()->delete();

        expect($this->usage->usage($this->organization, LimitKey::Customers))->toBe(3); // + the helper's customer
    });

    test('team members: counts active members only', function () {
        User::factory()->staff()->for($this->organization)->create(['status' => MemberStatus::Suspended]);
        User::factory()->staff()->for($this->organization)->create(['status' => MemberStatus::Removed]);

        expect($this->usage->usage($this->organization, LimitKey::TeamMembers))->toBe(3); // owner, manager, staff
    });

    test('automations: counts active automations only', function () {
        foreach ([AutomationStatus::Active, AutomationStatus::Active, AutomationStatus::Draft, AutomationStatus::Paused, AutomationStatus::Archived] as $status) {
            Automation::factory()->create(['organization_id' => $this->organization->id, 'status' => $status]);
        }

        expect($this->usage->usage($this->organization, LimitKey::Automations))->toBe(2);
    });

    test('emails: counts queued, sending and sent outbound emails; failed and inbound ones are free', function () {
        foreach ([MessageStatus::Queued, MessageStatus::Sending, MessageStatus::Sent] as $status) {
            outboundMessage($this->organization, $status);
        }
        outboundMessage($this->organization, MessageStatus::Failed);
        outboundMessage($this->organization, MessageStatus::Received, direction: MessageDirection::Inbound);

        expect($this->usage->usage($this->organization, LimitKey::OutboundEmails))->toBe(3);
    });

    test('estimates: counts new estimates, not revisions', function () {
        $first = Estimate::factory()->create(['customer_id' => $this->customer->id]);
        Estimate::factory()->create(['customer_id' => $this->customer->id, 'revision' => 2, 'estimate_number' => $first->estimate_number, 'revision_of_id' => $first->id]);

        expect($this->usage->usage($this->organization, LimitKey::Estimates))->toBe(1);
    });

    test('usage is isolated per organization', function () {
        ['organization' => $other, 'customer' => $otherCustomer] = teamBusiness('Other Co');
        Customer::factory()->count(5)->for($other)->create();
        outboundMessage($other);
        Estimate::factory()->create(['customer_id' => $otherCustomer->id]);

        expect($this->usage->all($this->organization))->toMatchArray(['customers' => 1, 'team_members' => 3, 'outbound_emails' => 0, 'estimates' => 0])
            ->and($this->usage->usage($other, LimitKey::Customers))->toBe(6)
            ->and($this->usage->usage($other, LimitKey::OutboundEmails))->toBe(1);
    });
});

// ─── Billing periods ─────────────────────────────────────────────────────────────────────

describe('billing periods', function () {
    test('monthly usage follows the subscription period, not the calendar month', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));
        subscribeWithPeriod($this->organization, 'starter', '2026-10-15 00:00', '2026-11-15 00:00');

        outboundMessage($this->organization, at: '2026-10-14 23:00'); // previous period
        outboundMessage($this->organization, at: '2026-10-16 09:00');
        outboundMessage($this->organization, at: '2026-10-19 09:00');

        expect($this->usage->usage($this->organization, LimitKey::OutboundEmails))->toBe(2)
            ->and($this->usage->period($this->organization)[0]->toDateTimeString())->toBe('2026-10-15 00:00:00');
    });

    test('a period that renewed before we heard about it rolls forward by whole months', function () {
        $this->travelTo(CarbonImmutable::parse('2026-12-20 12:00', 'UTC'));
        subscribeWithPeriod($this->organization, 'starter', '2026-10-15 00:00', '2026-11-15 00:00');

        [$start, $end] = $this->usage->period($this->organization);
        outboundMessage($this->organization, at: '2026-12-01 00:00'); // before 12-15
        outboundMessage($this->organization, at: '2026-12-16 00:00');

        expect($start->toDateTimeString())->toBe('2026-12-15 00:00:00')->and($end->toDateTimeString())->toBe('2027-01-15 00:00:00')
            ->and($this->usage->usage($this->organization, LimitKey::OutboundEmails))->toBe(1);
    });

    test('estimates reset at the start of the next billing period', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));
        subscribeWithPeriod($this->organization, 'starter', '2026-10-15 00:00', '2026-11-15 00:00');
        Estimate::factory()->create(['customer_id' => $this->customer->id, 'created_at' => '2026-10-16 00:00:00']);
        expect($this->usage->usage($this->organization, LimitKey::Estimates))->toBe(1);

        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00', 'UTC'));
        subscribeWithPeriod($this->organization->fresh(), 'starter', '2026-11-15 00:00', '2026-12-15 00:00');
        expect($this->usage->usage($this->organization, LimitKey::Estimates))->toBe(0);
    });

    test('without a subscription the calendar month in the business timezone is used', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));

        expect($this->usage->period($this->organization)[0]->toDateTimeString())->toBe('2026-10-01 05:00:00'); // Chicago midnight (CDT)
    });

    test('a subscription that no longer grants access falls back to the calendar month', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'UTC'));
        subscribeWithPeriod($this->organization, 'starter', '2026-10-15 00:00', '2026-11-15 00:00');
        Subscription::query()->update(['status' => SubscriptionStatus::Cancelled, 'ended_at' => now()]);

        expect($this->usage->period($this->organization)[0]->toDateTimeString())->toBe('2026-10-01 05:00:00');
    });
});

// ─── Centralized can… methods and the exception ──────────────────────────────────────────

describe('limit checks', function () {
    test('the can… methods answer for every limit and follow the plan', function () {
        tightFreePlan(['customers' => 1, 'team_members' => 3, 'automations' => 1, 'outbound_emails' => 1, 'estimates' => 1]);
        Automation::factory()->create(['organization_id' => $this->organization->id, 'status' => AutomationStatus::Active]);
        outboundMessage($this->organization);
        Estimate::factory()->create(['customer_id' => $this->customer->id]);

        expect(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeFalse()
            ->and(app(EntitlementService::class)->canAddTeamMember($this->organization))->toBeFalse() // owner, manager, staff = 3
            ->and(app(EntitlementService::class)->canCreateAutomation($this->organization))->toBeFalse()
            ->and(app(EntitlementService::class)->canSendEmail($this->organization))->toBeFalse()
            ->and(app(EntitlementService::class)->canCreateEstimate($this->organization))->toBeFalse();

        moveToPlan($this->organization, 'pro');

        expect(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeTrue()
            ->and(app(EntitlementService::class)->canAddTeamMember($this->organization))->toBeTrue()
            ->and(app(EntitlementService::class)->canCreateAutomation($this->organization))->toBeTrue()
            ->and(app(EntitlementService::class)->canSendEmail($this->organization))->toBeTrue()
            ->and(app(EntitlementService::class)->canCreateEstimate($this->organization))->toBeTrue();
    });

    test('the exception names the limit and the plan that lifts it', function () {
        tightFreePlan(['customers' => 1]);

        try {
            app(EntitlementService::class)->assertAllows($this->organization, LimitKey::Customers);
            $this->fail('Expected a PlanLimitException.');
        } catch (PlanLimitException $e) {
            expect($e->getMessage())->toBe('Your Free plan allows up to 1 customers. Upgrade to Starter to add more.')
                ->and($e->key)->toBe(LimitKey::Customers)->and($e->limit)->toBe(1)->and($e->upgradePlan?->key)->toBe('starter')
                ->and($e->upgradeUrl())->toBe(route('settings.billing'));
        }
    });

    test('the top plan has nothing to upgrade to', function () {
        config(['billing.plans.pro.limits.customers' => 1, 'billing.enforce_limits' => true]);
        app()->forgetInstance(PlanCatalog::class);
        moveToPlan($this->organization, 'pro');

        expect(fn () => app(EntitlementService::class)->assertAllows($this->organization, LimitKey::Customers))->toThrow(PlanLimitException::class, 'Upgrade your plan to add more.');
    });

    test('limits are not enforced when the switch is off', function () {
        tightFreePlan(['customers' => 1]);
        config(['billing.enforce_limits' => false]);

        expect(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeTrue();
    });
});

// ─── Enforcement points ──────────────────────────────────────────────────────────────────

describe('enforcement', function () {
    test('customers: the limit holds and organizations do not affect each other', function () {
        tightFreePlan(['customers' => 2]);
        $add = fn (User $by, string $email) => app(CustomerService::class)->create($by->fresh(), ['first_name' => 'New', 'last_name' => 'Person', 'email' => $email]);

        $add($this->owner, 'one@example.com'); // 2 of 2
        expect(fn () => $add($this->owner, 'two@example.com'))->toThrow(ValidationException::class, 'allows up to 2 customers');

        ['owner' => $otherOwner] = teamBusiness('Other Co'); // its own customer + room for one more
        expect($add($otherOwner, 'other@example.com')->email)->toBe('other@example.com');
    });

    test('team members: invitations hold seats and reactivating needs one', function () {
        tightFreePlan(['team_members' => 4]);
        $invite = fn (string $email) => app(InvitationService::class)->invite($this->owner->fresh(), 'Person', $email, OrganizationRole::Staff);

        $staff = User::where('organization_id', $this->organization->id)->where('role', OrganizationRole::Staff)->sole();
        app(TeamService::class)->suspend($this->owner, $staff);
        expect($this->usage->usage($this->organization, LimitKey::TeamMembers))->toBe(2);

        $invite('a@example.com');
        $invite('b@example.com'); // 2 members + 2 invitations = 4
        expect(fn () => $invite('c@example.com'))->toThrow(TeamActionException::class, 'allows up to 4 team members')
            ->and(fn () => app(TeamService::class)->reactivate($this->owner, $staff->fresh()))->toThrow(TeamActionException::class, 'allows up to 4 team members');
    });

    test('automations: only activating uses a slot', function () {
        tightFreePlan(['automations' => 1]);
        $builder = app(AutomationBuilder::class);
        $draft = fn (string $name) => $builder->save($this->organization, $this->owner, ['name' => $name, 'trigger_type' => 'estimate_sent', 'conditions' => [], 'actions' => [['type' => 'create_task', 'configuration' => ['title' => 'Call']]]]);

        $builder->activate($draft('One'), $this->owner);
        $second = $draft('Two'); // drafts are free

        expect(fn () => $builder->activate($second, $this->owner))->toThrow(PlanLimitException::class)
            ->and($second->fresh()->status)->toBe(AutomationStatus::Draft);
    });

    test('estimates: a refused estimate is not created and a refused customer email is not queued', function () {
        fakeEmailProvider();
        tightFreePlan(['estimates' => 1, 'outbound_emails' => 1]);
        app(EstimateService::class)->create($this->owner->fresh(), estimateInput($this->customer));

        expect(fn () => app(EstimateService::class)->create($this->owner->fresh(), estimateInput($this->customer)))->toThrow(EstimateException::class)
            ->and(Estimate::where('revision', 1)->count())->toBe(1);

        app(EmailService::class)->send($this->organization, 'pat@example.com', 'Hello', '<p>Hi</p>', 'Hi');
        expect(fn () => app(EmailService::class)->send($this->organization, 'pat@example.com', 'Again', '<p>Hi</p>', 'Hi'))->toThrow(EmailSendingNotAllowedException::class)
            ->and(Message::where('organization_id', $this->organization->id)->where('subject', 'Again')->exists())->toBeFalse();
    });
});

// ─── Failed operations ───────────────────────────────────────────────────────────────────

describe('failed operations', function () {
    test('an email that fails to send gives its place back', function () {
        fakeEmailProvider();
        tightFreePlan(['outbound_emails' => 1]);
        $message = app(EmailService::class)->send($this->organization, 'pat@example.com', 'Hello', '<p>Hi</p>', 'Hi');
        expect(app(EntitlementService::class)->canSendEmail($this->organization))->toBeFalse();

        $message->forceFill(['status' => MessageStatus::Failed])->save();

        expect(app(EntitlementService::class)->canSendEmail($this->organization))->toBeTrue();
    });

    test('a creation that fails after the check rolls back and uses no quota', function () {
        tightFreePlan(['customers' => 5]);
        $before = $this->usage->usage($this->organization, LimitKey::Customers);

        expect(fn () => app(EntitlementService::class)->guard($this->organization, LimitKey::Customers, function () {
            Customer::factory()->for($this->organization)->create();
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class);

        expect($this->usage->usage($this->organization, LimitKey::Customers))->toBe($before);
    });

    test('an invalid customer is rejected before it can use a slot', function () {
        tightFreePlan(['customers' => 5]);
        $before = $this->usage->usage($this->organization, LimitKey::Customers);

        expect(fn () => app(CustomerService::class)->create($this->owner->fresh(), ['first_name' => '', 'last_name' => '', 'email' => 'not-an-email']))->toThrow(ValidationException::class);

        expect($this->usage->usage($this->organization, LimitKey::Customers))->toBe($before);
    });
});

// ─── Concurrency ─────────────────────────────────────────────────────────────────────────

describe('concurrency', function () {
    test('the check and the insert run under the organization row lock, counting after the lock is taken', function () {
        tightFreePlan(['customers' => 100]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower(str_replace(['"', '`'], '', $query->sql));
        });

        app(CustomerService::class)->create($this->owner->fresh(), ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'lock@example.com']);

        $position = fn (string $needle) => collect($queries)->search(fn ($sql) => str_contains($sql, $needle));
        $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'from organizations') && str_contains($sql, 'for update'));
        $count = collect($queries)->search(fn ($sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'from customers'));

        expect($lock)->not->toBeFalse()->and($count)->toBeGreaterThan($lock)->and($position('insert into customers'))->toBeGreaterThan($count);
    });

    test('every guarded creation takes the lock before counting', function () {
        tightFreePlan(['customers' => 100, 'estimates' => 100, 'outbound_emails' => 100, 'automations' => 100, 'team_members' => 100]);
        fakeEmailProvider();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower(str_replace(['"', '`'], '', $query->sql));
        });
        $locks = function () use (&$queries) {
            return collect($queries)->filter(fn ($sql) => str_contains($sql, 'from organizations') && str_contains($sql, 'for update'))->count();
        };

        app(EstimateService::class)->create($this->owner->fresh(), estimateInput($this->customer));
        expect($locks())->toBe(1);

        app(EmailService::class)->send($this->organization, 'pat@example.com', 'Hello', '<p>Hi</p>', 'Hi');
        expect($locks())->toBe(2);

        app(InvitationService::class)->invite($this->owner->fresh(), 'Person', 'p@example.com', OrganizationRole::Staff);
        expect($locks())->toBe(3);
    });

    test('99 of 100 customers: exactly one of two back-to-back requests succeeds', function () {
        tightFreePlan(['customers' => 100]);
        Customer::factory()->count(98)->for($this->organization)->create(); // + the helper's customer = 99
        $add = fn (string $email) => app(CustomerService::class)->create($this->owner->fresh(), ['first_name' => 'New', 'last_name' => 'Person', 'email' => $email]);

        $results = collect(['a@example.com', 'b@example.com'])->map(function ($email) use ($add) {
            try {
                return $add($email) ? 'created' : 'failed';
            } catch (ValidationException) {
                return 'refused';
            }
        });

        expect($results->all())->toBe(['created', 'refused'])->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(100);
    });
});

// ─── Downgrades ──────────────────────────────────────────────────────────────────────────

describe('downgrades', function () {
    test('nothing is deleted when a plan shrinks; only adding is refused', function () {
        moveToPlan($this->organization, 'pro');
        Customer::factory()->count(150)->for($this->organization)->create();
        foreach (range(1, 6) as $i) {
            Automation::factory()->create(['organization_id' => $this->organization->id, 'status' => AutomationStatus::Active]);
        }
        outboundMessage($this->organization);

        Subscription::query()->update(['status' => SubscriptionStatus::Cancelled, 'ended_at' => now()]); // back to Free (100 customers, 3 automations)
        config(['billing.enforce_limits' => true]);

        $summary = app(EntitlementService::class)->summary($this->organization);
        expect($summary['customers'])->toMatchArray(['used' => 151, 'limit' => 100, 'remaining' => 0, 'over' => true])
            ->and($summary['automations'])->toMatchArray(['used' => 6, 'limit' => 3, 'over' => true])
            ->and(Customer::where('organization_id', $this->organization->id)->count())->toBe(151)
            ->and(Automation::where('organization_id', $this->organization->id)->count())->toBe(6)
            ->and(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeFalse()
            ->and(fn () => app(CustomerService::class)->create($this->owner->fresh(), ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'x@example.com']))->toThrow(ValidationException::class);

        // Existing records stay usable: they can still be edited.
        $customer = Customer::where('organization_id', $this->organization->id)->first();
        expect(app(CustomerService::class)->update($this->owner->fresh(), $customer, ['first_name' => 'Renamed', 'last_name' => $customer->last_name, 'email' => $customer->email, 'status' => $customer->status->value])->first_name)->toBe('Renamed');
    });

    test('dropping back under the limit allows adding again', function () {
        tightFreePlan(['customers' => 3]);
        Customer::factory()->count(3)->for($this->organization)->create(); // 4 of 3
        expect(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeFalse();

        Customer::where('organization_id', $this->organization->id)->limit(2)->get()->each->delete(); // 2 of 3

        expect(app(EntitlementService::class)->canCreateCustomer($this->organization))->toBeTrue();
    });
});
