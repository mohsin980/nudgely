<?php

use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Platform\PlatformRole;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Platform\OrganizationSuspension;
use App\Services\Platform\PlanSettingsService;
use App\Services\Platform\UserAccountService;
use App\Support\Audit\AuditRedactor;
use App\Support\CorrelationId;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| SA-10: Audit logs and administrative activity
|--------------------------------------------------------------------------
*/

function auditAdmin(PlatformRole $role = PlatformRole::SuperAdmin): User
{
    return platformAdmin($role, ['organization_id' => null]);
}

function auditEvent(string $action = 'plan_updated', array $attributes = []): PlatformAuditLog
{
    $log = PlatformAuditLog::record($action, $attributes['actor'] ?? null, $attributes['organization_id'] ?? null, $attributes['reason'] ?? null, null, $attributes['changes'] ?? null,
        $attributes['target_type'] ?? 'plan', $attributes['target_id'] ?? 'pro', $attributes['outcome'] ?? 'success');

    if (isset($attributes['created_at'])) {
        DB::table('platform_audit_logs')->where('id', $log->id)->update(['created_at' => $attributes['created_at']]);
    }

    return $log->fresh();
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Redaction

test('keys that name secrets or message content are redacted whatever their value', function () {
    $clean = AuditRedactor::sanitize([
        'plan' => 'pro',
        'password' => 'hunter2',
        'new_password_confirmation' => 'hunter2',
        'api_key' => 'abc',
        'stripe_secret' => 'abc',
        'access_token' => 'abc',
        'Authorization' => 'abc',
        'card_number' => '4242',
        'cvc' => '123',
        'remember_token' => 'abc',
        'body' => 'Dear customer, your estimate...',
        'html' => '<p>hi</p>',
        'nested' => ['client_secret' => 'abc', 'count' => 3],
    ]);

    expect($clean['plan'])->toBe('pro')
        ->and($clean['nested'])->toBe(['client_secret' => '[redacted]', 'count' => 3]);

    foreach (['password', 'new_password_confirmation', 'api_key', 'stripe_secret', 'access_token', 'Authorization', 'card_number', 'cvc', 'remember_token', 'body', 'html'] as $key) {
        expect($clean[$key])->toBe('[redacted]', $key);
    }
});

test('secret-looking values are redacted wherever they appear, and addresses and card numbers are masked', function () {
    $clean = AuditRedactor::sanitize([
        'note' => 'used sk_live_51HabcDEF123456789 and whsec_abcdef123456 today',
        'header' => 'Bearer abcdefghijklmnop1234567890',
        'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
        'opaque' => str_repeat('a1B2', 12),
        'card' => 'paid with 4242 4242 4242 4242 yesterday',
        'who' => 'contacted jane.doe@example.com about it',
        'order' => 'invoice 1234567890123',
    ]);

    $flat = json_encode($clean);

    foreach (['sk_live_', 'whsec_abc', 'abcdefghijklmnop', 'eyJhbGci', str_repeat('a1B2', 12), '4242 4242', 'jane.doe@example.com'] as $secret) {
        expect($flat)->not->toContain($secret);
    }

    expect($clean['who'])->toBe('contacted [email] about it')
        ->and($clean['order'])->toBe('invoice 1234567890123'); // a number that is not a card (fails the Luhn check) is kept
});

test('depth, width and string length are capped and objects are never serialized', function () {
    $clean = AuditRedactor::sanitize([
        'long' => str_repeat('x ', 600),
        'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 1]]]]]],
        'object' => new ArrayObject(['secret' => 'x']),
        'when' => now(),
        'wide' => array_fill(0, 200, 1),
    ]);

    expect(mb_strlen($clean['long']))->toBeLessThanOrEqual(500)
        ->and(json_encode($clean['deep']))->toContain('[truncated]')
        ->and($clean['object'])->toBe('[ArrayObject]')
        ->and($clean['when'])->toBeString()
        ->and(count($clean['wide']))->toBe(50);
});

// Recording

test('record() redacts the reason and the details and fills the target and request ID', function () {
    Context::add(CorrelationId::ATTRIBUTE, 'req-abc-123');
    $organization = Organization::factory()->create();

    $log = PlatformAuditLog::record('organization_suspended', null, $organization->id, 'Chargeback, contact owner@acme.test, key sk_live_ABCDEF123456', null,
        ['token' => 'secret-token', 'plan' => 'pro']);

    expect($log->reason)->toBe('Chargeback, contact [email], key [redacted]')
        ->and($log->changes)->toBe(['token' => '[redacted]', 'plan' => 'pro'])
        ->and($log->target_type)->toBe('organization')->and($log->target_id)->toBe((string) $organization->id)
        ->and($log->correlation_id)->toBe('req-abc-123')
        ->and($log->outcome)->toBe('success')->and($log->actor_id)->toBeNull();
});

test('the database never holds the secrets that were passed in', function () {
    PlatformAuditLog::record('plan_updated', null, null, 'token whsec_live123456789', null, ['api_key' => 'sk_live_REALKEY1234567', 'note' => 'Bearer abcdefghijklmnopqrst']);

    $raw = json_encode(DB::table('platform_audit_logs')->get());

    expect($raw)->not->toContain('REALKEY')->not->toContain('whsec_live')->not->toContain('abcdefghijklmnopqrst');
});

// Append-only

test('audit events cannot be edited or deleted through the model', function () {
    $log = auditEvent();

    expect(fn () => $log->forceFill(['reason' => 'changed'])->save())->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(fn () => PlatformAuditLog::query()->first()->delete())->toThrow(LogicException::class);
});

test('no role can create, edit or delete audit events, and the panel offers none of it', function () {
    $log = auditEvent();
    $admin = auditAdmin();
    $this->actingAs($admin);

    foreach (['create', 'update', 'delete'] as $ability) {
        expect($admin->can($ability, $ability === 'create' ? PlatformAuditLog::class : $log))->toBeFalse($ability);
    }

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($log))->toBeFalse()
        ->and(AuditLogResource::canDelete($log))->toBeFalse()
        ->and(AuditLogResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(AuditLogResource::getPages()))->toBe(['index', 'view']);
});

// Coverage of sensitive actions

test('organization suspension and reactivation are audited with actor, target and reason', function () {
    $admin = auditAdmin();
    $organization = Organization::factory()->create();

    app(OrganizationSuspension::class)->suspend($organization, $admin, 'Repeated abuse reports received');
    app(OrganizationSuspension::class)->reactivate($organization, $admin, 'Resolved with the business owner');

    $events = PlatformAuditLog::query()->orderBy('id')->get();
    expect($events->pluck('action')->all())->toBe(['organization_suspended', 'organization_reactivated'])
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$admin->id])
        ->and($events->first()->target_type)->toBe('organization')->and($events->first()->target_id)->toBe((string) $organization->id)
        ->and($events->first()->reason)->toBe('Repeated abuse reports received');
});

test('user deactivation, plan changes and platform access changes are audited', function () {
    $admin = auditAdmin();
    $customer = User::factory()->staff()->create();

    app(UserAccountService::class)->deactivate($customer, $admin, 'Compromised credentials reported');
    app(PlanSettingsService::class)->update('pro', 'Pro', [...collect(LimitKey::cases())->mapWithKeys(fn ($k) => [$k->value => app(PlanCatalog::class)->get('pro')->limit($k)])->all(), 'customers' => 2500], $admin, 'Raising customer limit for growth');

    $target = User::factory()->create(['email' => 'grantee@example.com']);
    Artisan::call('platform-admin:grant', ['email' => 'grantee@example.com', '--role' => ['support_admin']]);
    Artisan::call('platform-admin:revoke', ['email' => 'grantee@example.com']);

    $byAction = PlatformAuditLog::query()->get()->keyBy('action');
    expect($byAction)->toHaveKeys(['user_deactivated', 'plan_updated', 'platform_admin_granted', 'platform_admin_revoked'])
        ->and($byAction['user_deactivated']->target_id)->toBe((string) $customer->id)
        ->and($byAction['plan_updated']->target_type)->toBe('plan')->and($byAction['plan_updated']->target_id)->toBe('pro')
        ->and($byAction['platform_admin_granted']->actor_id)->toBeNull()
        ->and($byAction['platform_admin_granted']->target_id)->toBe((string) $target->id)
        ->and($byAction['platform_admin_granted']->changes['roles'])->toBe(['support_admin'])
        ->and($byAction['platform_admin_revoked']->changes['previous_roles'])->toBe(['support_admin']);
});

test('a refused sensitive action is audited as denied and changes nothing', function () {
    $support = auditAdmin(PlatformRole::SupportAdmin);
    $organization = Organization::factory()->create();
    $customer = User::factory()->staff()->create();

    expect(fn () => app(OrganizationSuspension::class)->suspend($organization, $support, 'Support must not be able to do this'))->toThrow(AuthorizationException::class);
    expect(fn () => app(UserAccountService::class)->deactivate($customer, $support, 'Support must not be able to do this'))->toThrow(AuthorizationException::class);
    expect(fn () => app(PlanSettingsService::class)->reset('pro', $support, 'Support must not be able to do this'))->toThrow(AuthorizationException::class);

    $denied = PlatformAuditLog::query()->where('outcome', 'denied')->orderBy('id')->get();
    expect($denied->pluck('action')->all())->toBe(['organization_suspended', 'user_deactivated', 'plan_reset'])
        ->and($denied->pluck('actor_id')->unique()->all())->toBe([$support->id])
        ->and($denied->pluck('changes')->filter()->all())->toBe([])
        ->and($organization->fresh()->isSuspended())->toBeFalse()
        ->and(PlatformAuditLog::where('outcome', 'success')->count())->toBe(0);
});

test('a customer calling a platform service leaves a denied event too', function () {
    $owner = User::factory()->owner()->create();
    $organization = Organization::factory()->create();

    expect(fn () => app(OrganizationSuspension::class)->suspend($organization, $owner, 'A customer trying to suspend someone'))->toThrow(AuthorizationException::class);

    expect(PlatformAuditLog::sole())->toMatchArray(['outcome' => 'denied', 'actor_id' => $owner->id, 'action' => 'organization_suspended']);
});

test('an admin session start is audited once per session, not once per request', function () {
    $admin = auditAdmin();

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->get('/admin')->assertOk();
    $this->get('/admin')->assertOk();

    expect(PlatformAuditLog::where('action', 'admin_session_started')->count())->toBe(1)
        ->and(PlatformAuditLog::where('action', 'admin_session_started')->sole()->actor_id)->toBe($admin->id);
});

// Access control

test('only administrators with view_audit_logs can open the audit log', function () {
    auditEvent();

    $this->get('/admin/audit-logs')->assertRedirect('/admin/login');

    foreach ([PlatformRole::SupportAdmin, PlatformRole::SuperAdmin] as $role) {
        $this->actingAs(auditAdmin($role))->get('/admin/audit-logs')->assertOk();
    }

    $this->actingAs(auditAdmin(PlatformRole::BillingAdmin))->get('/admin/audit-logs')->assertForbidden();
    expect(AuditLogResource::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->owner()->create())->get('/admin/audit-logs')->assertForbidden();
});

test('the detail page shows the event and sanitized details, never secrets', function () {
    $this->actingAs(auditAdmin());
    $log = auditEvent('plan_updated', ['changes' => ['plan' => 'pro', 'api_key' => 'sk_live_NEVERSHOWN1234', 'fields' => ['limits.customers' => ['from' => 2000, 'to' => 2500]]], 'reason' => 'Raising limits']);

    Livewire::test(ViewAuditLog::class, ['record' => $log->id])
        ->assertSee('Plan updated')->assertSee('Raising limits')->assertSee('limits.customers')->assertSee('[redacted]')
        ->assertDontSee('NEVERSHOWN');
});

// List: search, filters, pagination, scale

test('the list is paginated newest first with simple pagination', function () {
    $this->actingAs(auditAdmin());
    foreach (range(1, 60) as $i) {
        auditEvent('plan_updated', ['target_id' => "plan{$i}"]);
    }

    $list = Livewire::test(ListAuditLogs::class);
    $records = $list->instance()->getTableRecords();

    expect($records)->toHaveCount(25)
        ->and($records->first()->id)->toBeGreaterThan($records->last()->id)
        ->and($records)->toBeInstanceOf(Paginator::class);
});

test('listing does not count the whole table', function () {
    $this->actingAs(auditAdmin());
    auditEvent();
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListAuditLogs::class)->instance()->getTableRecords();

    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    expect(strtolower($sql))->not->toContain('count(*)');
});

test('the listing query count does not grow with the number of events', function () {
    $this->actingAs(auditAdmin());
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListAuditLogs::class)->instance()->getTableRecords();

        return count(DB::getQueryLog());
    };

    auditEvent('plan_updated', ['actor' => User::factory()->create(), 'organization_id' => Organization::factory()->create()->id]);
    $count();
    $small = $count();
    foreach (range(1, 40) as $i) {
        auditEvent('plan_updated', ['actor' => User::factory()->create(), 'organization_id' => Organization::factory()->create()->id]);
    }

    expect($count())->toBe($small);
});

test('search finds events by action name or label, target ID, organization, actor and request ID', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    $organization = Organization::factory()->create();
    Context::add(CorrelationId::ATTRIBUTE, 'req-needle-1');
    $suspend = auditEvent('organization_suspended', ['actor' => $admin, 'organization_id' => $organization->id, 'target_type' => 'organization', 'target_id' => $organization->id]);
    Context::add(CorrelationId::ATTRIBUTE, 'req-other');
    $plan = auditEvent('plan_updated', ['target_id' => 'starter']);

    $found = fn (string $term) => AuditLogResource::search(PlatformAuditLog::query(), $term)->pluck('id')->sort()->values()->all();

    expect($found('suspend'))->toBe([$suspend->id])
        ->and($found('Plan updated'))->toBe([$plan->id])
        ->and($found('starter'))->toBe([$plan->id])
        ->and($found((string) $organization->id))->toContain($suspend->id)
        ->and($found('req-needle-1'))->toBe([$suspend->id])
        ->and($found('nothing-matches-this'))->toBe([]);
});

test('filters narrow by action, outcome, actor, organization and period', function () {
    $admin = auditAdmin();
    $other = auditAdmin();
    $this->actingAs($admin);
    $organization = Organization::factory()->create();
    $a = auditEvent('organization_suspended', ['actor' => $admin, 'organization_id' => $organization->id, 'target_type' => 'organization', 'target_id' => $organization->id, 'created_at' => '2026-10-01 10:00:00']);
    $b = auditEvent('plan_updated', ['actor' => $other, 'outcome' => 'denied', 'created_at' => '2026-10-10 10:00:00']);
    $c = auditEvent('plan_reset', ['actor' => $other, 'created_at' => '2026-10-20 10:00:00']);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('action', 'plan_updated')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])
        ->resetTableFilters()
        ->filterTable('outcome', 'denied')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])
        ->resetTableFilters()
        ->filterTable('actor_id', $admin->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('organization', ['organization_id' => $organization->id])->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c])
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-10-05', 'until' => '2026-10-15'])->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-10-20', 'until' => '2026-10-20'])->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
});

// Retention

test('retention removes only expired events, in batches, and records that it did', function () {
    config(['admin.audit_retention_days' => 90]);
    $old = collect(range(1, 5))->map(fn () => auditEvent('plan_updated', ['created_at' => now()->subDays(120)]));
    $edge = auditEvent('plan_updated', ['created_at' => now()->subDays(89)]);
    $recent = auditEvent('plan_updated');

    $this->artisan('platform-audit:prune --dry-run')->expectsOutputToContain('5 audit event(s)')->assertSuccessful();
    expect(PlatformAuditLog::count())->toBe(7);

    $this->artisan('platform-audit:prune')->assertSuccessful();

    expect(PlatformAuditLog::whereIn('id', $old->pluck('id'))->count())->toBe(0)
        ->and(PlatformAuditLog::whereIn('id', [$edge->id, $recent->id])->count())->toBe(2);

    $event = PlatformAuditLog::where('action', 'audit_log_pruned')->sole();
    expect($event->actor_id)->toBeNull()->and($event->changes['deleted'])->toBe(5)->and($event->changes['retention_days'])->toBe(90);
});

test('a dangerously short retention period is refused', function () {
    config(['admin.audit_retention_days' => 1]);
    auditEvent('plan_updated', ['created_at' => now()->subDays(5)]);

    $this->artisan('platform-audit:prune')->assertFailed();

    expect(PlatformAuditLog::count())->toBe(1);
});
