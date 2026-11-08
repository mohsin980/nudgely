<?php

use App\Events\CustomerCreated;
use App\Jobs\ImportLeadAdJob;
use App\Livewire\Settings\LeadAdsSettings;
use App\Models\Customer;
use App\Models\LeadAdsLead;
use App\Models\LeadAdsPage;
use App\Services\LeadAds\LeadAdsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

const LA_PAGE = '555000111222';
const LA_LEAD = '900000000001';
const LA_FORM = '777000';
const LA_SECRET = 'platform-app-secret-0123456789';

beforeEach(function () {
    $this->team = teamBusiness(); // owner John Smith, manager Sarah Wilson, staff Mike Johnson; Dallas HVAC
    $this->owner = $this->team['owner'];
    $this->organization = $this->team['organization'];

    config(['services.whatsapp.embedded' => ['app_id' => '1234567890', 'app_secret' => LA_SECRET, 'config_id' => null, 'verify_token' => 'platform-verify-token']]);
});

/** Meta's answers for signing in and listing Pages. */
function metaListsPages(): void
{
    Http::fake([
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '1234567890']]),
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAB-long-lived-user-token']),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            ['id' => LA_PAGE, 'name' => 'Dallas HVAC', 'access_token' => 'EAAB-page-token-1'],
            ['id' => '555000999888', 'name' => 'Other Page', 'access_token' => 'EAAB-page-token-2'],
        ]]),
        'graph.facebook.com/*/'.LA_PAGE.'/subscribed_apps' => Http::response(['success' => true]),
    ]);
}

function connectLeadAdsPage($test): LeadAdsPage
{
    metaListsPages();
    Livewire::actingAs($test->owner)->test(LeadAdsSettings::class)
        ->call('loadPages', 'short-lived-user-token')
        ->set('selected', [LA_PAGE])
        ->call('connectPages');

    return LeadAdsPage::query()->where('page_id', LA_PAGE)->sole();
}

/** A lead Meta returns, with its form answers. */
function metaReturnsLead(array $fields, array $extra = []): void
{
    Http::fake([
        'graph.facebook.com/*/'.LA_LEAD.'*' => Http::response(['id' => LA_LEAD, 'created_time' => '2026-11-08T09:00:00+0000', 'form_id' => LA_FORM, 'ad_id' => '123', 'ad_name' => 'Spring AC offer', 'platform' => 'ig',
            'field_data' => collect($fields)->map(fn ($value, $name) => ['name' => $name, 'values' => [$value]])->values()->all()] + $extra),
        'graph.facebook.com/*/'.LA_FORM.'*' => Http::response(['name' => 'Free quote']),
    ]);
}

function leadWebhook(string $secret = LA_SECRET): TestResponse
{
    // Meta is never signed in.
    auth()->guard('web')->forgetUser();
    auth()->guard('admin')->forgetUser();

    $payload = json_encode(['object' => 'page', 'entry' => [['id' => LA_PAGE, 'time' => time(), 'changes' => [['field' => 'leadgen', 'value' => [
        'leadgen_id' => LA_LEAD, 'page_id' => LA_PAGE, 'form_id' => LA_FORM, 'ad_id' => '123', 'created_time' => time(),
    ]]]]]]);

    return test()->call('POST', '/webhooks/meta/leads', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret)], $payload);
}

// ─── Connecting ─────────────────────────────────────────────────────────────────────────

test('the owner signs in with Facebook, picks a Page, and it is subscribed to new leads', function () {
    metaListsPages();

    $page = Livewire::actingAs($this->owner)->test(LeadAdsSettings::class)
        ->assertSee('Connect Facebook Page')
        ->call('loadPages', 'short-lived-user-token')
        ->assertSee('Which Pages should send leads to QuoteFlow?')->assertSee('Other Page')
        ->assertDontSee('EAAB-page-token') // tokens never reach the page
        ->set('selected', [LA_PAGE])
        ->call('connectPages')
        ->assertSee('is connected');

    $page = LeadAdsPage::query()->sole();
    expect($page)->organization_id->toBe($this->organization->id)->page_name->toBe('Dallas HVAC')->page_access_token->toBe('EAAB-page-token-1')
        ->and(DB::table('lead_ads_pages')->value('page_access_token'))->not->toContain('EAAB'); // encrypted

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'debug_token') && $r->hasHeader('Authorization', 'Bearer 1234567890|'.LA_SECRET));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), LA_PAGE.'/subscribed_apps') && $r['subscribed_fields'] === 'leadgen' && $r->hasHeader('Authorization', 'Bearer EAAB-page-token-1'));
});

test('a Facebook sign-in issued to another app is refused', function () {
    Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '999']])]);

    Livewire::actingAs($this->owner)->test(LeadAdsSettings::class)
        ->call('loadPages', 'someone-elses-token')
        ->assertSee('not for QuoteFlow')->assertSet('choices', []);
});

test('a Page connected to another business cannot be taken over', function () {
    $other = teamBusiness('Austin Plumbing');
    metaListsPages();
    Livewire::actingAs($other['owner'])->test(LeadAdsSettings::class)->call('loadPages', 'token')->set('selected', [LA_PAGE])->call('connectPages');

    Livewire::actingAs($this->owner)->test(LeadAdsSettings::class)
        ->call('loadPages', 'token')->assertSee('Connected to another business')
        ->set('selected', [LA_PAGE])->call('connectPages')->assertSee('already connected to another business');

    expect(LeadAdsPage::query()->sole()->organization_id)->toBe($other['organization']->id);
});

test('only the owner can manage Lead Ads, and the page explains when it is not set up', function () {
    $this->actingAs($this->team['manager'])->get('/settings/lead-ads')->assertForbidden();

    config(['services.whatsapp.embedded.app_secret' => null]);
    Livewire::actingAs($this->owner)->test(LeadAdsSettings::class)
        ->assertSee("Lead Ads isn't set up on this server yet", false)->assertDontSee('Connect Facebook Page');
});

// ─── Receiving leads ────────────────────────────────────────────────────────────────────

test('a lead form submission becomes a customer, starts automations and notifies the team', function () {
    Event::fake([CustomerCreated::class]);
    $page = connectLeadAdsPage($this);
    $page->forceFill(['assign_to' => $this->team['staff']->id, 'whatsapp_consent' => true])->save();
    metaReturnsLead(['full_name' => 'Ali Raza', 'email' => 'Ali@Example.com', 'phone_number' => '+1 214 555 0177', 'when_do_you_need_it?' => 'This week']);

    leadWebhook()->assertOk();

    $lead = LeadAdsLead::query()->sole();
    $customer = Customer::query()->where('organization_id', $this->organization->id)->where('email', 'ali@example.com')->sole();

    expect($lead)->status->toBe(LeadAdsLead::STATUS_IMPORTED)->customer_id->toBe($customer->id)->form_name->toBe('Free quote')->platform->toBe('ig')->ad_name->toBe('Spring AC offer')
        ->and($customer)->name->toBe('Ali Raza')->phone_e164->toBe('+12145550177')->assigned_to->toBe($this->team['staff']->id)
        ->whatsapp_opt_in_source->toBe('lead_ad')->notes->toContain('Lead from Instagram ad “Spring AC offer” (form “Free quote”)')->toContain('When Do You Need It?: This week');

    Event::assertDispatched(CustomerCreated::class, fn ($e) => $e->customerId === $customer->id);

    $notified = DB::table('notifications')->get()->map(fn ($n) => [$n->notifiable_id, json_decode($n->data, true)['message'] ?? ''])->all();
    expect($notified)->toContain([$this->team['staff']->id, 'New lead from your Instagram lead ad “Free quote”: Ali Raza.'])
        ->toContain([$this->owner->id, 'New lead from your Instagram lead ad “Free quote”: Ali Raza.']);
});

test('without WhatsApp consent on the Page, a lead is not opted in to WhatsApp', function () {
    connectLeadAdsPage($this);
    metaReturnsLead(['full_name' => 'Ali Raza', 'phone_number' => '+1 214 555 0177']);

    leadWebhook()->assertOk();

    expect(Customer::query()->where('phone_e164', '+12145550177')->sole()->whatsapp_opt_in_at)->toBeNull();
});

test('a lead from someone already in customers updates them instead of duplicating', function () {
    $existing = Customer::factory()->for($this->organization)->create(['name' => 'Ali R', 'email' => 'ali@example.com']);
    connectLeadAdsPage($this);
    metaReturnsLead(['full_name' => 'Ali Raza', 'email' => 'ali@example.com', 'budget' => '$5,000']);

    leadWebhook()->assertOk();

    expect(LeadAdsLead::query()->sole())->status->toBe(LeadAdsLead::STATUS_MATCHED)->customer_id->toBe($existing->id)
        ->and(Customer::query()->where('organization_id', $this->organization->id)->where('email', 'ali@example.com')->count())->toBe(1)
        ->and($existing->fresh()->notes)->toContain('Budget: $5,000');
});

test('the same lead delivered twice is imported once', function () {
    connectLeadAdsPage($this);
    metaReturnsLead(['full_name' => 'Ali Raza', 'email' => 'ali@example.com']);

    leadWebhook()->assertOk();
    leadWebhook()->assertOk();

    expect(LeadAdsLead::query()->count())->toBe(1)
        ->and(Customer::query()->where('email', 'ali@example.com')->count())->toBe(1);
});

test('webhooks not signed with QuoteFlow\'s app secret, or for unknown Pages, are refused or ignored', function () {
    Queue::fake();
    leadWebhook('wrong-secret')->assertStatus(401);
    leadWebhook()->assertOk(); // Page not connected: ignored

    expect(LeadAdsLead::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('Meta can verify the leads webhook with the platform token', function () {
    $this->get('/webhooks/meta/leads?hub_mode=subscribe&hub_verify_token=platform-verify-token&hub_challenge=42')->assertOk()->assertSeeText('42');
    $this->get('/webhooks/meta/leads?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=42')->assertForbidden();
});

test('when the plan\'s customer limit is reached the lead is kept as not added, with the reason', function () {
    tightFreePlan(['customers' => Customer::query()->where('organization_id', $this->organization->id)->count()]);
    config(['billing.enforce_limits' => true]);
    connectLeadAdsPage($this);
    metaReturnsLead(['full_name' => 'Ali Raza', 'email' => 'ali@example.com']);

    leadWebhook()->assertOk();

    expect(LeadAdsLead::query()->sole())->status->toBe(LeadAdsLead::STATUS_FAILED)->error->toContain('customer limit');
});

test('leads of a suspended business wait and are imported after reactivation', function () {
    connectLeadAdsPage($this);
    metaReturnsLead(['full_name' => 'Ali Raza', 'email' => 'ali@example.com']);
    $this->organization->forceFill(['suspended_at' => now()])->save();

    leadWebhook()->assertOk();
    expect(LeadAdsLead::query()->sole()->status)->toBe(LeadAdsLead::STATUS_PENDING);

    $this->organization->forceFill(['suspended_at' => null])->save();
    app(LeadAdsService::class)->import(LeadAdsLead::query()->sole()->id);

    expect(LeadAdsLead::query()->sole()->status)->toBe(LeadAdsLead::STATUS_IMPORTED);
});

test('the sync picks up a lead whose webhook never arrived', function () {
    Queue::fake();
    $page = connectLeadAdsPage($this);
    Http::fake([
        'graph.facebook.com/*/'.LA_PAGE.'/leadgen_forms*' => Http::response(['data' => [['id' => LA_FORM, 'name' => 'Free quote']]]),
        'graph.facebook.com/*/'.LA_FORM.'/leads*' => Http::response(['data' => [['id' => LA_LEAD, 'created_time' => '2026-11-08T09:00:00+0000']]]),
    ]);

    expect(app(LeadAdsService::class)->sync())->toBe(1)
        ->and(app(LeadAdsService::class)->sync())->toBe(0);

    expect(LeadAdsLead::query()->sole())->leadgen_id->toBe(LA_LEAD)->lead_ads_page_id->toBe($page->id);
    Queue::assertPushed(ImportLeadAdJob::class, 1);
});

test('the Page settings choose who handles new leads, only from the team', function () {
    $page = connectLeadAdsPage($this);
    $outsider = teamBusiness('Austin Plumbing')['staff'];

    Livewire::actingAs($this->owner)->test(LeadAdsSettings::class)
        ->set("pageSettings.{$page->id}.assign_to", (string) $outsider->id)->call('savePage', $page->id)->assertSee('Choose an active member of your team.')
        ->set("pageSettings.{$page->id}.assign_to", (string) $this->team['manager']->id)->set("pageSettings.{$page->id}.whatsapp_consent", true)->call('savePage', $page->id);

    expect($page->fresh())->assign_to->toBe($this->team['manager']->id)->whatsapp_consent->toBeTrue();
});

test('disconnecting a Page unsubscribes it and keeps the customers already added', function () {
    connectLeadAdsPage($this);
    metaReturnsLead(['full_name' => 'Ali Raza', 'email' => 'ali@example.com']);
    leadWebhook()->assertOk();
    $page = LeadAdsPage::query()->sole();

    Livewire::actingAs($this->owner)->test(LeadAdsSettings::class)->call('disconnect', $page->id);

    expect(LeadAdsPage::query()->exists())->toBeFalse()
        ->and(Customer::query()->where('email', 'ali@example.com')->exists())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), LA_PAGE.'/subscribed_apps'));
});
