<?php

use App\Enums\FollowUpStatus;
use App\Livewire\Settings\AccountSecurity;
use App\Livewire\Settings\Concerns\ManagesSubscription;
use App\Models\Automation;
use App\Models\BillingWebhookEvent;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use App\Providers\AppServiceProvider;
use App\Services\Email\EmailHtmlSanitizer;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'organization' => $this->organization, 'customer' => $this->customer] = teamBusiness('Alpha HVAC');
});

// ─── Security headers ────────────────────────────────────────────────────────────────────

test('every web response carries the security headers', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
});

test('the content security policy closes objects, framing, foreign forms and base URLs', function () {
    $policy = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->toContain("default-src 'self'")->toContain("object-src 'none'")->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")->toContain("form-action 'self'");
});

test('the csp can be enforced or switched off by configuration', function () {
    config(['security.csp' => 'enforce']);
    $enforced = $this->get('/login');
    expect($enforced->headers->has('Content-Security-Policy'))->toBeTrue()
        ->and($enforced->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();

    config(['security.csp' => 'off']);
    $off = $this->get('/login');
    expect($off->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($off->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

test('authenticated pages are also protected', function () {
    $this->actingAs($this->owner)->get('/dashboard')->assertHeader('X-Frame-Options', 'DENY');
});

test('the session cookie is secure in production and HttpOnly + SameSite everywhere', function () {
    expect(config('session.http_only'))->toBeTrue()->and(config('session.same_site'))->toBeIn(['lax', 'strict']);
    $config = file_get_contents(base_path('config/session.php'));
    expect($config)->toContain("env('APP_ENV') === 'production'");
});

test('debug is forced off in production', function () {
    config(['app.debug' => true]);
    $this->app['env'] = 'production';
    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeFalse();
});

// ─── Sessions ────────────────────────────────────────────────────────────────────────────

test('changing the password signs out every other session and rotates the remember token', function () {
    DB::table('sessions')->insert([
        ['id' => 'other-browser', 'user_id' => $this->owner->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
        ['id' => 'a-teammate', 'user_id' => $this->manager->id, 'ip_address' => '1.2.3.5', 'user_agent' => 'y', 'payload' => '', 'last_activity' => time()],
    ]);
    $this->owner->forceFill(['password' => Hash::make('old-Password-123'), 'remember_token' => 'old-token'])->save();

    Livewire::actingAs($this->owner)->test(AccountSecurity::class)
        ->set('currentPassword', 'old-Password-123')->set('newPassword', 'New-Password-456!x')->set('newPasswordConfirmation', 'New-Password-456!x')
        ->call('changePassword')->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-browser')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'a-teammate')->exists())->toBeTrue()
        ->and($this->owner->fresh()->remember_token)->not->toBe('old-token');
});

test('the wrong current password changes nothing', function () {
    $this->owner->forceFill(['password' => Hash::make('old-Password-123')])->save();
    $hash = $this->owner->fresh()->password;

    Livewire::actingAs($this->owner)->test(AccountSecurity::class)
        ->set('currentPassword', 'nope')->set('newPassword', 'New-Password-456!x')->set('newPasswordConfirmation', 'New-Password-456!x')
        ->call('changePassword')->assertHasErrors('currentPassword');

    expect($this->owner->fresh()->password)->toBe($hash);
});

// ─── Rate limits ─────────────────────────────────────────────────────────────────────────

test('billing actions are rate limited per organization', function () {
    $key = "billing-actions:{$this->organization->id}";
    RateLimiter::clear($key);
    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit($key, 600);
    }
    expect(RateLimiter::tooManyAttempts($key, 30))->toBeTrue();
    expect(collect((new ReflectionClass(ManagesSubscription::class))->getMethods())->map->getName())->toContain('attempt');
    expect(file_get_contents(app_path('Livewire/Settings/Concerns/ManagesSubscription.php')))->toContain('billing-actions:');
});

test('the billing route group uses the billing throttle', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_contains($r->uri(), 'payment-method'));
    expect($route->gatherMiddleware())->toContain('throttle:billing');
});

// ─── Mass assignment & browser-supplied billing state ────────────────────────────────────

test('is_default and status cannot be mass assigned', function () {
    $connection = (new EmailConnection(['provider' => 'postmark', 'domain' => 'x.com', 'sender_email' => 'a@x.com', 'sender_name' => 'A', 'is_default' => true, 'organization_id' => 999]));
    expect($connection->is_default)->toBeFalse()->and($connection->organization_id)->toBeNull();

    $automation = new Automation(['name' => 'A', 'trigger_type' => 'customer_reply_classified', 'status' => 'active', 'organization_id' => 999]);
    expect($automation->status?->value)->not->toBe('active')->and($automation->organization_id)->toBeNull();
});

test('organization billing columns are not mass assignable', function () {
    $organization = Organization::factory()->create();
    $organization->update(['billing_customer_id' => 'cus_attacker', 'plan' => 'enterprise']);

    expect($organization->fresh()->billing_customer_id)->not->toBe('cus_attacker');
});

test('no browser request can set plan, price or status on billing pages', function () {
    $this->actingAs($this->owner)->post('/settings/billing', ['plan' => 'enterprise', 'status' => 'active'])->assertStatus(405);
    expect(Subscription::where('organization_id', $this->organization->id)->where('plan', 'enterprise')->exists())->toBeFalse();
});

// ─── Sanitising, XSS ─────────────────────────────────────────────────────────────────────

test('inbound html loses scripts, handlers, images and javascript links', function () {
    $clean = app(EmailHtmlSanitizer::class)->sanitize('<p onclick="x()">Hi</p><script>alert(1)</script><img src="http://t.example/p.png"><a href="javascript:alert(1)">go</a><iframe src="//e.com"></iframe>');

    expect($clean)->toContain('Hi')->not->toContain('script')->not->toContain('onclick')->not->toContain('<img')
        ->not->toContain('javascript:')->not->toContain('iframe');
});

test('customer-supplied names are escaped wherever they render', function () {
    $customer = Customer::factory()->for($this->organization)->create(['name' => '<script>alert("x")</script>']);

    $this->actingAs($this->owner)->get(route('customers.show', $customer))->assertOk()
        ->assertDontSee('<script>alert("x")</script>', false);
});

// ─── DB tenant triggers ──────────────────────────────────────────────────────────────────

test('the database refuses a record that links to another organization', function () {
    $other = teamBusiness('Beta Plumbing');

    expect(fn () => DB::transaction(fn () => DB::table('conversations')->insert([
        'organization_id' => $this->organization->id, 'customer_id' => $other['customer']->id, 'subject' => 'x', 'status' => 'open',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'Tenant integrity');
});

test('the database allows a record linked within the same organization', function () {
    expect(DB::table('conversations')->insert([
        'organization_id' => $this->organization->id, 'customer_id' => $this->customer->id, 'subject' => 'x', 'status' => 'open',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toBeTrue();
});

// ─── Queue / job re-checks ───────────────────────────────────────────────────────────────

test('a follow-up cancelled after it was queued is not sent', function () {
    Queue::fake();
    fakeEmailProvider();
    [, $customer, $conversation] = followUpBusiness();
    $followUp = automatedFollowUp($conversation);
    makeDue($followUp);
    $before = Message::where('direction', 'outbound')->count();

    $followUp->forceFill(['status' => FollowUpStatus::Cancelled])->save();
    app(FollowUpProcessor::class)->process($followUp->id);

    expect(Message::where('direction', 'outbound')->count())->toBe($before);
});

test('a customer reply after queueing stops the automated follow-up', function () {
    Queue::fake();
    fakeEmailProvider();
    [, , $conversation] = followUpBusiness();
    $followUp = automatedFollowUp($conversation);
    makeDue($followUp);
    $before = Message::where('direction', 'outbound')->count();

    customerReply($conversation);
    app(FollowUpProcessor::class)->process($followUp->id);

    expect(Message::where('direction', 'outbound')->count())->toBe($before);
});

// ─── Webhooks ────────────────────────────────────────────────────────────────────────────

test('stripe webhooks with a bad or missing signature are rejected and recorded nowhere', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    $event = stripeEvent('customer.subscription.updated', ['id' => 'sub_1']);

    webhook($event, null)->assertStatus(400);
    webhook($event, 'whsec_wrong')->assertStatus(400);
    webhook($event, 'whsec_test_secret', time() - 3600)->assertStatus(400);
    expect(BillingWebhookEvent::count())->toBe(0);
});

test('inbound email webhooks require the right basic-auth credentials', function () {
    config(['email.providers.postmark.inbound_webhook_username' => 'postmark', 'email.providers.postmark.inbound_webhook_secret' => 'inbound-secret', 'email.inbound.reply_domain' => 'inbound.quoteflow.ai']);
    Queue::fake();
    $url = '/webhooks/email/inbound/postmark';
    $payload = ['From' => 'a@example.com', 'To' => 'reply+x@inbound.quoteflow.ai', 'MessageID' => 'abc-1', 'Subject' => 's', 'TextBody' => 'hi'];

    $this->postJson($url, $payload)->assertUnauthorized();
    $this->postJson($url, $payload, ['Authorization' => 'Basic '.base64_encode('postmark:wrong')])->assertUnauthorized();
    $this->postJson($url, $payload, ['Authorization' => 'Basic '.base64_encode('postmark:inbound-secret')])->assertStatus(422); // authenticated, then rejected as an unroutable payload
});
