<?php

use App\Exceptions\Estimates\EstimateException;
use App\Livewire\Automations\AutomationForm;
use App\Livewire\Automations\AutomationLogs;
use App\Livewire\Automations\ShowAutomation;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\Estimates\EstimateForm;
use App\Livewire\Estimates\ShowEstimate;
use App\Livewire\FollowUps\FollowUpIndex;
use App\Livewire\Inbox\ShowConversation;
use App\Livewire\Settings\BillingOverview;
use App\Livewire\Settings\EmailSettings;
use App\Livewire\Settings\TeamMembers;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Subscription;
use App\Models\Task;
use App\Services\Billing\BillingService;
use App\Services\Customers\CustomerService;
use App\Services\Estimates\EstimateService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Task 16A: two businesses with equivalent data. Everything one can see or do must stop at its own border,
 * whichever door is used: the page URL, a crafted Livewire call, a forged property or a direct service call.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->a = tenant('Alpha HVAC');
    $this->b = tenant('Bravo HVAC');
});

/** A business with one of everything. */
function tenant(string $name): array
{
    $t = teamBusiness($name);
    $organization = $t['organization'];
    $customer = $t['customer'];
    $customer->forceFill(['name' => 'John Smith', 'email' => 'john@example.com'])->save();
    $t['conversation'] = Conversation::factory()->for($customer)->create(['organization_id' => $organization->id, 'subject' => 'Your HVAC estimate', 'last_message_at' => now()]);
    $t['message'] = customerReply($t['conversation']);
    $t['estimate'] = draftEstimate($t['owner'], $customer);
    $t['automation'] = Automation::factory()->active()->create(['organization_id' => $organization->id, 'name' => 'Ready to Book']);
    $t['run'] = AutomationRun::query()->forceCreate(['organization_id' => $organization->id, 'automation_id' => $t['automation']->id, 'conversation_id' => $t['conversation']->id,
        'event_type' => 'customer_reply_classified', 'event_id' => 'c:'.$organization->id, 'status' => 'completed']);
    $t['followUp'] = automatedFollowUp($t['conversation']);
    $t['task'] = Task::query()->forceCreate(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'conversation_id' => $t['conversation']->id, 'title' => 'Call John', 'priority' => 'medium', 'status' => 'pending']);
    $t['connection'] = $organization->emailConnections()->sole();
    $slug = strtolower(strtok($name, ' '));
    $t['connection']->forceFill(['sender_email' => "hello@{$slug}hvac.com", 'domain' => "{$slug}hvac.com"])->save();
    $t['subscription'] = moveToPlan($organization, 'starter');

    return $t;
}

// ─── Pages by URL ────────────────────────────────────────────────────────────────────────

test('1 to 7. a user cannot open another business\'s records by changing the URL', function () {
    $user = $this->a['owner'];
    $b = $this->b;

    // The legacy address only redirects, to the same permission-checked page.
    $this->actingAs($user)->get("/inbox/{$b['conversation']->id}")->assertRedirect(route('inbox.show', $b['conversation']->id));

    foreach ([
        "/customers/{$b['customer']->id}", "/customers/{$b['customer']->id}/edit",
        "/conversations/{$b['conversation']->id}",
        "/estimates/{$b['estimate']->id}", "/estimates/{$b['estimate']->id}/edit",
        "/automations/{$b['automation']->id}", "/automations/{$b['automation']->id}/edit", "/automations/{$b['automation']->id}/logs", "/automations/{$b['automation']->id}/logs/{$b['run']->id}",
    ] as $url) {
        $status = $this->actingAs($user)->get($url)->status();
        expect($status)->toBeIn([403, 404, 301, 302], "GET {$url} returned {$status}");
        $this->actingAs($user)->get($url)->assertDontSee('Bravo HVAC');
    }
});

test('a user\'s own pages work (the checks above are not just blocking everything)', function () {
    $a = $this->a;

    foreach (["/customers/{$a['customer']->id}", "/conversations/{$a['conversation']->id}", "/estimates/{$a['estimate']->id}", "/automations/{$a['automation']->id}", "/automations/{$a['automation']->id}/logs/{$a['run']->id}"] as $url) {
        $this->actingAs($a['owner'])->get($url)->assertOk();
    }
});

test('a B customer or conversation id in the estimate form URL is ignored', function () {
    $page = Livewire::withQueryParams(['customer' => $this->b['customer']->id, 'conversation' => $this->b['conversation']->id])->actingAs($this->a['owner'])->test(EstimateForm::class);

    expect($page->get('customerId'))->toBe('')->and($page->get('conversationId'))->toBe('');
});

// ─── Livewire: mounting someone else's record ────────────────────────────────────────────

test('Livewire: mounting a B record as an A user is denied', function (string $component, string $param, string $resource) {
    $response = Livewire::withoutLazyLoading()->actingAs($this->a['owner'])->test($component, [$param => $this->b[$resource]->id]);

    try {
        $response->assertNotFound();
    } catch (Throwable) {
        $response->assertForbidden();
    }
})->with([
    'customer page' => [ShowCustomer::class, 'customerId', 'customer'],
    'customer form' => [CustomerForm::class, 'customerId', 'customer'],
    'conversation' => [ShowConversation::class, 'conversationId', 'conversation'],
    'estimate page' => [ShowEstimate::class, 'estimateId', 'estimate'],
    'estimate form' => [EstimateForm::class, 'estimateId', 'estimate'],
    'automation page' => [ShowAutomation::class, 'automationId', 'automation'],
    'automation form' => [AutomationForm::class, 'automationId', 'automation'],
    'automation logs' => [AutomationLogs::class, 'automationId', 'automation'],
]);

// ─── Livewire: crafted calls inside one's own page ───────────────────────────────────────

test('15. a crafted Livewire call cannot act on another business\'s task, message, follow-up or customer', function () {
    $a = $this->a;
    $b = $this->b;

    $conversation = Livewire::withoutLazyLoading()->actingAs($a['owner'])->test(ShowConversation::class, ['conversationId' => $a['conversation']->id]);
    foreach ([['completeTask', [$b['task']->id]], ['assignTask', [$b['task']->id, (string) $a['manager']->id]], ['reclassify', [$b['message']->id]]] as [$method, $args]) {
        try {
            $conversation->call($method, ...$args);
        } catch (Throwable) {
        }
    }

    expect($b['task']->fresh()->status->value)->toBe('pending')->and($b['task']->fresh()->assigned_to)->toBeNull()
        ->and(MessageClassification::where('message_id', $b['message']->id)->count())->toBe(0);

    $followUps = Livewire::withoutLazyLoading()->actingAs($a['owner'])->test(FollowUpIndex::class);
    foreach (['complete', 'cancel', 'assign', 'reschedule'] as $form) {
        try {
            $followUps->call('openFollowUpForm', $b['followUp']->id, $form);
        } catch (Throwable) {
        }
    }
    try {
        $followUps->call('sendFollowUp', $b['followUp']->id);
    } catch (Throwable) {
    }
    expect($b['followUp']->fresh()->status->value)->toBe('pending')->and($followUps->get('activeFollowUpId'))->toBeNull();
});

test('15b. an A follow-up cannot be scheduled for a B customer, nor assigned to a B user', function () {
    $a = $this->a;
    $b = $this->b;
    $before = FollowUp::query()->where('organization_id', $a['organization']->id)->count();

    $page = Livewire::withoutLazyLoading()->actingAs($a['owner'])->test(FollowUpIndex::class)->call('openScheduleForm')
        ->set('scheduleCustomerId', (string) $b['customer']->id)->set('scheduleDate', now()->addDay()->toDateString())->set('scheduleTime', '10:00')->call('scheduleFollowUp');
    expect(FollowUp::query()->where('customer_id', $b['customer']->id)->count())->toBe(1) // only B's own
        ->and(FollowUp::query()->where('organization_id', $a['organization']->id)->count())->toBe($before);

    $page->set('scheduleCustomerId', (string) $a['customer']->id)->set('scheduleAssignee', (string) $b['owner']->id)->call('scheduleFollowUp');
    expect(FollowUp::query()->where('organization_id', $a['organization']->id)->whereIn('assigned_to', [$b['owner']->id, $b['manager']->id])->count())->toBe(0);
});

test('15c. an estimate cannot be created for another business\'s customer or conversation', function () {
    $a = $this->a;
    $b = $this->b;

    Livewire::actingAs($a['owner'])->test(EstimateForm::class)->call('selectCustomer', $b['customer']->id);
    $page = Livewire::actingAs($a['owner'])->test(EstimateForm::class)->set('customerId', (string) $b['customer']->id)->set('title', 'Stolen')->set('items', [['description' => 'x', 'quantity' => '1', 'unit_price' => '10']])->call('save');
    expect(Estimate::where('organization_id', $a['organization']->id)->where('title', 'Stolen')->count())->toBe(0)
        ->and(Estimate::where('customer_id', $b['customer']->id)->where('title', 'Stolen')->count())->toBe(0);
});

test('15d. a crafted email send from a customer page uses only the signed-in business\'s sender and customers', function () {
    $a = $this->a;
    $b = $this->b;
    fakeEmailProvider();

    try {
        Livewire::withoutLazyLoading()->actingAs($a['owner'])->test(ShowCustomer::class, ['customerId' => $b['customer']->id])->set('emailSubject', 'Hi')->set('emailBody', 'Hi')->call('sendEmail');
    } catch (Throwable) {
    }

    expect(Message::where('organization_id', $b['organization']->id)->where('direction', 'outbound')->count())->toBe(0);
});

test('13 and 14. locked ids and forged identity fields cannot be changed from the browser', function () {
    $a = $this->a;
    $b = $this->b;

    $page = Livewire::withoutLazyLoading()->actingAs($a['owner'])->test(ShowCustomer::class, ['customerId' => $a['customer']->id]);
    expect(fn () => $page->set('customerId', $b['customer']->id))->toThrow(Exception::class);

    $form = Livewire::actingAs($a['owner'])->test(CustomerForm::class, ['customerId' => $a['customer']->id]);
    expect(fn () => $form->set('customerId', $b['customer']->id))->toThrow(Exception::class);

    // Organization, owner and creator ids are not properties of any component the browser can set.
    $props = collect([ShowCustomer::class, CustomerForm::class, EstimateForm::class, AutomationForm::class, TeamMembers::class, EmailSettings::class, BillingOverview::class])
        ->flatMap(fn ($c) => collect((new ReflectionClass($c))->getProperties(ReflectionProperty::IS_PUBLIC))->map(fn ($p) => class_basename($c).'::'.$p->getName()));
    expect($props->filter(fn ($p) => preg_match('/(organization|org)_?id$|created_?by|owner_?id|user_?id$/i', $p))->values()->all())->toBe([]);
});

// ─── Team and settings ───────────────────────────────────────────────────────────────────

test('team actions cannot touch another business\'s members', function () {
    $a = $this->a;
    $b = $this->b;

    $page = Livewire::actingAs($a['owner'])->test(TeamMembers::class);
    $page->assertDontSee('Bravo HVAC')->assertDontSee($b['manager']->email)->assertDontSee($b['staff']->email);

    foreach ([['suspend', [$b['manager']->id]], ['changeRole', [$b['staff']->id, 'manager']], ['startRemoval', [$b['staff']->id]], ['reactivate', [$b['manager']->id]]] as [$method, $args]) {
        try {
            $page->call($method, ...$args);
        } catch (Throwable) {
        }
    }

    expect($b['manager']->fresh()->status->value)->toBe('active')->and($b['staff']->fresh()->role->value)->toBe('staff')->and($b['staff']->fresh()->status->value)->toBe('active');
});

test('email connections of another business cannot be read, changed, verified or used', function () {
    $a = $this->a;
    $b = $this->b;
    $provider = fakeEmailProvider();
    $page = Livewire::actingAs($a['owner'])->test(EmailSettings::class);
    $page->assertDontSee($b['connection']->sender_email)->assertDontSee($b['connection']->domain);

    foreach (['edit', 'confirmDelete', 'setDefault', 'startVerification', 'toggleDnsRecords', 'checkVerification', 'openTestEmail'] as $method) {
        try {
            $page->call($method, $b['connection']->id);
        } catch (Throwable) {
        }
    }
    try {
        $page->set('testEmailTo', 'attacker@example.com')->call('sendTestEmail');
    } catch (Throwable) {
    }

    expect(EmailConnection::find($b['connection']->id))->not->toBeNull()
        ->and($b['connection']->fresh()->only(['sender_email', 'domain', 'is_default', 'verification_status']))->toEqual($b['connection']->only(['sender_email', 'domain', 'is_default', 'verification_status']))
        ->and(Message::where('organization_id', $b['organization']->id)->where('to_address', 'attacker@example.com')->count())->toBe(0);
});

test('7. billing pages show only the signed-in business', function () {
    $a = $this->a;
    $b = $this->b;
    $b['subscription']->forceFill(['plan' => 'pro'])->save();

    Livewire::actingAs($a['owner'])->test(BillingOverview::class)->assertSee('Starter')->assertSee('$29.00')->assertDontSee('$79.00');
    expect(Subscription::where('organization_id', $a['organization']->id)->count())->toBe(1)->and(app(BillingService::class)->currentSubscription($a['organization'])->plan)->toBe('starter');
    expect(fn () => app(BillingService::class)->changePlan($a['manager'], 'pro'))->toThrow(AuthorizationException::class);
});

// ─── Services and queries ────────────────────────────────────────────────────────────────

test('services refuse ids from another business', function () {
    $a = $this->a;
    $b = $this->b;

    expect(fn () => app(CustomerService::class)->update($a['owner'], $b['customer'], ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'status' => 'active']))->toThrow(HttpException::class)
        ->and(fn () => app(EstimateService::class)->send($a['owner'], $b['estimate']))->toThrow(EstimateException::class)
        ->and(fn () => app(EstimateService::class)->create($a['owner'], estimateInput($b['customer'])))->toThrow(EstimateException::class);

    expect($b['customer']->fresh()->first_name)->not->toBe('X');
});
