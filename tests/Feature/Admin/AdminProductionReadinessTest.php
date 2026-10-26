<?php

use App\Enums\Platform\PlatformRole;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SA-12: Production readiness of the Super Admin panel
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

test('no admin page triggers lazy loading (N+1) with many rows', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);

    foreach (range(1, 6) as $i) {
        $organization = Organization::factory()->create(['name' => "Org {$i}", 'timezone' => 'UTC']);
        User::factory()->count(2)->staff()->create(['organization_id' => $organization->id]);
        Subscription::query()->forceCreate(['organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => "sub_ready_{$i}", 'plan' => 'pro', 'status' => 'active']);
        PlatformAuditLog::record('organization_suspended', $admin, $organization->id, 'Readiness check reason');
        Customer::factory()->for($organization)->create();
    }

    Model::preventLazyLoading();
    Model::handleLazyLoadingViolationUsing(fn ($model, $relation) => throw new LazyLoadingViolationException($model, $relation));

    $organization = Organization::query()->first();
    $subscription = Subscription::query()->first();
    $audit = PlatformAuditLog::query()->first();
    $member = User::query()->where('organization_id', $organization->id)->first();

    foreach (['/admin', '/admin/organizations', "/admin/organizations/{$organization->id}", '/admin/users', "/admin/users/{$member->id}", '/admin/plans',
        '/admin/subscriptions', "/admin/subscriptions/{$subscription->id}", '/admin/usage', "/admin/usage/{$organization->id}", '/admin/audit-logs', "/admin/audit-logs/{$audit->id}"] as $url) {
        test()->actingAs($admin)->get($url)->assertOk();
    }
});

test('production does not expose exception details on admin URLs', function () {
    config(['app.debug' => false, 'app.env' => 'production']);
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
    Route::middleware('web')->get('/admin/readiness-boom', fn () => throw new RuntimeException('database password is hunter2 at /var/www/secret.php'));

    $response = test()->actingAs($admin)->get('/admin/readiness-boom');

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('hunter2')->not->toContain('secret.php')->not->toContain('RuntimeException')->not->toContain('vendor/');
});

test('the scheduler lists the audit pruning and no admin feature needs a queue worker', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('platform-audit:prune');

    $queued = collect(glob(app_path('{Filament,Services/Platform,Support/Admin,Support/Audit}/**/*.php'), GLOB_BRACE))
        ->filter(fn ($file) => str_contains((string) file_get_contents($file), 'ShouldQueue'));
    expect($queued->all())->toBe([]);
});

test('every new migration can be rolled back, and the seeder can be run repeatedly', function () {
    $new = ['create_platform_admins_table', 'create_permission_tables', 'add_suspension_and_platform_audit', 'add_subject_to_platform_audit_logs',
        'create_plan_settings', 'add_last_synced_at_to_subscriptions', 'index_usage_counts', 'extend_platform_audit_logs'];

    $files = collect(glob(database_path('migrations/*.php')))->filter(fn ($file) => collect($new)->contains(fn ($name) => str_contains($file, $name)));
    expect($files)->toHaveCount(count($new));

    foreach ($files as $file) {
        $migration = require $file;
        expect(method_exists($migration, 'down'))->toBeTrue(basename($file));
        // Additive only: up() never drops or rewrites existing tables or columns.
        $up = Str::between((string) file_get_contents($file), 'function up()', 'function down()');
        expect($up)->not->toContain('dropColumn')->not->toContain('dropIfExists')->not->toContain('drop(')->not->toContain('truncate')->not->toContain('->change()')->not->toContain('renameColumn');
    }

    seedPlatformRoles();
    $before = [DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count()];
    seedPlatformRoles();
    seedPlatformRoles();

    expect([DB::table('roles')->count(), DB::table('permissions')->count(), DB::table('role_has_permissions')->count()])->toBe($before);
});

test('no route creates a platform administrator and no credential is hardcoded', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $suspicious = $routes->filter(fn ($route) => preg_match('/admin.*(register|create-admin|grant|promote|invite)|platform-admin/i', $route->uri()))->map->uri()->values()->all();
    expect($suspicious)->toBe([]);

    $sources = collect(glob(app_path('**/*.php')) + glob(app_path('*/*/*.php')) + glob(app_path('*/*/*/*.php')) + glob(base_path('database/seeders/*.php')) + glob(base_path('config/*.php')))
        ->map(fn ($file) => file_get_contents($file))->implode("\n");

    expect($sources)->not->toMatch('/sk_live_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}|Hash::make\(\s*[\'"][^\'"]{4,}[\'"]\s*\)/');
});

test('existing customer routes still work next to the admin panel', function () {
    ['owner' => $owner] = teamBusiness();

    foreach (['/login', '/register'] as $guestPage) {
        test()->get($guestPage)->assertOk();
    }

    foreach (['/customers', '/conversations', '/estimates', '/automations', '/settings/security'] as $page) {
        test()->actingAs($owner)->get($page)->assertSuccessful();
    }

    test()->get('/health/ready')->assertSuccessful();
});

test('with debug off, routine outcomes keep their proper responses instead of a generic 500', function () {
    config(['app.debug' => false, 'app.env' => 'production']);
    ['owner' => $owner] = teamBusiness();

    // Guests are sent to sign in (customer app and admin panel).
    test()->get('/customers')->assertRedirect('/login');
    test()->get('/dashboard')->assertRedirect('/login');
    test()->get('/admin')->assertRedirect('/admin/login');
    test()->get('/admin/organizations/1')->assertRedirect('/admin/login');

    // A failed sign-in shows its validation message (it used to be a 500).
    test()->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertRedirect('/login')->assertSessionHasErrors('email');

    // Missing records are 404, forbidden pages are 403.
    test()->actingAs($owner)->get('/customers/999999')->assertNotFound();
    test()->actingAs($owner)->get('/admin')->assertForbidden();
    test()->get('/definitely-not-a-page')->assertNotFound();
});

test('with debug off, a customer-facing page fails closed with a generic page and a reference', function () {
    config(['app.debug' => false, 'app.env' => 'production']);
    Route::middleware('web')->get('/readiness-customer-boom', fn () => throw new RuntimeException('internal detail 10.0.0.5'));

    $response = test()->get('/readiness-customer-boom');

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('internal detail')->not->toContain('10.0.0.5');
});
