<?php

use App\Enums\Platform\PlatformRole;
use App\Filament\Pages\Plans;
use App\Filament\Pages\UsageDetails;
use App\Filament\Pages\UsageMonitoring;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\UsersRelationManager;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\Inbox\ConversationList;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Fakes\FakeStripe;

/*
|--------------------------------------------------------------------------
| SA-11: Tenant isolation, customer app and admin panel
|--------------------------------------------------------------------------
| The customer app is already covered record by record in TenantIsolationTest (Task 16A). These tests add what that file
| does not: search, filters and related-record pages, query-string tampering, customers calling platform components, and
| the admin panel's own organization-scoped pages (relation tables, usage, invoices, audit filters).
*/

/** Two businesses with look-alike data, so a leak shows up as the other business's distinctive name. */
function twoTenants(): array
{
    $a = teamBusiness('Alpha HVAC');
    $b = teamBusiness('Bravo HVAC');

    foreach ([['a', $a, 'Alpha'], ['b', $b, 'Bravo']] as [, $t, $label]) {
        $t['customer']->forceFill(['name' => "Pat {$label}", 'first_name' => 'Pat', 'last_name' => $label, 'email' => strtolower($label).'@example.com'])->save();
        Conversation::factory()->for($t['customer'])->create(['organization_id' => $t['organization']->id, 'subject' => "{$label} private subject", 'last_message_at' => now()]);
    }

    return [$a, $b];
}

beforeEach(function () {
    $this->withoutVite();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Customer app: search, filters, related pages, tampering

test('customer search and filters never return another business\'s records', function () {
    [$a, $b] = twoTenants();

    Livewire::actingAs($a['owner'])->test(CustomerIndex::class)->set('search', 'Pat')->assertSee('Pat Alpha')->assertDontSee('Pat Bravo')
        ->set('search', 'bravo')->assertDontSee('Pat Bravo')
        ->set('search', '')->set('status', 'active')->assertDontSee('Pat Bravo');

    Livewire::actingAs($a['owner'])->test(ConversationList::class)->set('search', 'private')->assertSee('Alpha private subject')->assertDontSee('Bravo private subject')
        ->set('search', 'Bravo')->assertDontSee('Bravo private subject');

    Livewire::actingAs($b['owner'])->test(ConversationList::class)->assertSee('Bravo private subject')->assertDontSee('Alpha private subject');
});

test('related-record pages show only the business\'s own conversations and estimates', function () {
    [$a, $b] = twoTenants();
    draftEstimate($b['owner'], $b['customer']);

    Livewire::withoutLazyLoading()->actingAs($a['owner'])->test(ShowCustomer::class, ['customerId' => $a['customer']->id])
        ->assertSee('Alpha private subject')->assertDontSee('Bravo private subject')->assertDontSee('Bravo');
});

test('organization IDs and record IDs in the query string are ignored', function () {
    [$a, $b] = twoTenants();
    $query = http_build_query(['organization_id' => $b['organization']->id, 'organization' => $b['organization']->id, 'customer_id' => $b['customer']->id, 'search' => '', 'user_id' => $b['owner']->id]);

    foreach (['/customers', '/conversations', '/estimates', '/follow-ups', '/settings/team', '/automations'] as $path) {
        $response = test()->actingAs($a['owner'])->get("{$path}?{$query}");
        expect($response->status())->toBeIn([200, 403, 302], $path);
        $response->assertDontSee('Bravo');
    }
});

test('a business user cannot open a platform component directly, whichever door they use', function () {
    [$a] = twoTenants();
    $classes = [ListOrganizations::class, ListUsers::class, ListSubscriptions::class, ListAuditLogs::class, Plans::class, UsageMonitoring::class];

    foreach ([$a['owner'], $a['manager'], $a['staff']] as $user) {
        test()->actingAs($user);

        foreach ($classes as $class) {
            Livewire::test($class)->assertForbidden();
        }

        Livewire::test(UsageDetails::class, ['organizationId' => $a['organization']->id])->assertForbidden();
        Livewire::test(ViewOrganization::class, ['record' => $a['organization']->id])->assertForbidden();
    }
});

// Admin panel: scoped related pages

test('the organization\'s users table lists only that organization\'s members', function () {
    [$a, $b] = twoTenants();
    test()->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));

    Livewire::test(UsersRelationManager::class, ['ownerRecord' => $a['organization'], 'pageClass' => ViewOrganization::class])
        ->assertCanSeeTableRecords([$a['owner'], $a['manager'], $a['staff']])
        ->assertCanNotSeeTableRecords([$b['owner'], $b['manager'], $b['staff']]);
});

test('admin search and filters narrow correctly and never mix organizations', function () {
    [$a, $b] = twoTenants();
    test()->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));

    Livewire::test(ListOrganizations::class)->searchTable('Alpha')->assertCanSeeTableRecords([$a['organization']])->assertCanNotSeeTableRecords([$b['organization']]);
    Livewire::test(ListUsers::class)->filterTable('organization_id', $a['organization']->id)
        ->assertCanSeeTableRecords([$a['owner']])->assertCanNotSeeTableRecords([$b['owner'], $b['staff']]);
});

test('usage details count only the organization they are for', function () {
    [$a, $b] = twoTenants();
    Customer::factory()->count(4)->for($a['organization'])->create();
    test()->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));

    $row = fn ($organization) => Livewire::test(UsageDetails::class, ['organizationId' => $organization->id])->get('row');

    expect($row($a['organization'])['limits']['customers']['used'])->toBe(5) // 4 + the business's own customer
        ->and($row($b['organization'])['limits']['customers']['used'])->toBe(1);
});

test('audit log filters by organization without mixing events', function () {
    [$a, $b] = twoTenants();
    $forA = PlatformAuditLog::record('organization_suspended', null, $a['organization']->id);
    $forB = PlatformAuditLog::record('organization_suspended', null, $b['organization']->id);
    test()->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));

    Livewire::test(ListAuditLogs::class)->filterTable('organization', ['organization_id' => $a['organization']->id])
        ->assertCanSeeTableRecords([$forA])->assertCanNotSeeTableRecords([$forB]);
});

test('a subscription page loads invoices for its own organization only', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    $stripe = FakeStripe::install();
    $subscriptions = [];

    foreach (['Alpha HVAC' => 'INV-ALPHA', 'Bravo HVAC' => 'INV-BRAVO'] as $name => $number) {
        $t = teamBusiness($name);
        $session = app(BillingService::class)->startCheckout($t['owner'], 'starter', 'https://a', 'https://b');
        $remote = $stripe->completeCheckout($session->id);
        $customer = $t['organization']->fresh()->billing_customer_id;
        webhook(stripeEvent('customer.subscription.created', ['id' => $remote['id'], 'customer' => $customer]))->assertOk();
        $stripe->invoices[$customer] = [['id' => "in_{$number}", 'number' => $number, 'created' => 1760000000, 'total' => 2900, 'currency' => 'usd', 'status' => 'paid']];
        $subscriptions[$number] = Subscription::query()->where('organization_id', $t['organization']->id)->sole();
    }

    test()->actingAs(platformAdmin(PlatformRole::BillingAdmin, ['organization_id' => null]));

    Livewire::test(ViewSubscription::class, ['record' => $subscriptions['INV-ALPHA']->id])->callAction('loadInvoices')
        ->assertSee('INV-ALPHA')->assertDontSee('INV-BRAVO');
    Livewire::test(ViewSubscription::class, ['record' => $subscriptions['INV-BRAVO']->id])->callAction('loadInvoices')
        ->assertSee('INV-BRAVO')->assertDontSee('INV-ALPHA');
});

// Platform-wide listings must not change customer queries

test('platform queries do not change how the customer app scopes its data', function () {
    [$a, $b] = twoTenants();

    $before = Customer::query()->where('organization_id', $a['organization']->id)->pluck('id')->all();
    test()->actingAs(platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]));
    Livewire::test(ListOrganizations::class);
    Livewire::test(ListUsers::class);

    // No global scope was added to customer-side models: an ordinary query is still just that organization's rows.
    expect(Customer::query()->where('organization_id', $a['organization']->id)->pluck('id')->all())->toBe($before)
        ->and((new Customer)->getGlobalScopes())->not->toHaveKey('platform')
        ->and(User::query()->toRawSql())->not->toContain('platform');
});
