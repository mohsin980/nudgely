<?php

use App\Enums\EstimateStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Estimates\EstimateException;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Estimates\EstimateForm;
use App\Livewire\Estimates\EstimateIndex;
use App\Livewire\Estimates\ShowEstimate;
use App\Livewire\Inbox\ShowConversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\Conversations\TimelineService;
use App\Services\Dashboard\DashboardService;
use App\Services\Estimates\EstimateService;
use App\Services\FollowUps\FollowUpService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    [$this->admin, $this->john, $this->conversation] = estimateBusiness();
    $this->provider = fakeEmailProvider();
    $this->estimates = app(EstimateService::class);
});

// Organization isolation

test('organization A cannot see organization B estimates', function () {
    [$otherAdmin, $otherCustomer] = estimateBusiness('Houston Plumbing');
    $otherCustomer->update(['name' => 'Foreign Fred']);
    $foreign = sentEstimate($otherAdmin, $otherCustomer, ['title' => 'Foreign water heater']);
    $mine = draftEstimate($this->admin, $this->john);

    Livewire::actingAs($this->admin)->test(EstimateIndex::class)
        ->assertSee($mine->title)->assertDontSee('Foreign water heater')->assertDontSee('Foreign Fred');
    Livewire::actingAs($this->admin)->test(EstimateIndex::class)->set('search', 'Foreign')->assertDontSee('Foreign water heater');

    $this->actingAs($this->admin)->get(route('estimates.show', $foreign->id))->assertNotFound();
    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $foreign->id])->assertNotFound();
});

test('organization A cannot edit, send, revise or cancel organization B estimates', function () {
    [$otherAdmin, $otherCustomer] = estimateBusiness('Houston Plumbing');
    $draft = draftEstimate($otherAdmin, $otherCustomer);
    $sent = sentEstimate($otherAdmin, $otherCustomer);

    $this->actingAs($this->admin)->get(route('estimates.edit', $draft->id))->assertNotFound();
    Livewire::actingAs($this->admin)->test(EstimateForm::class, ['estimateId' => $draft->id])->assertNotFound();

    foreach ([
        fn () => $this->estimates->update($this->admin, $draft, estimateInput($otherCustomer)),
        fn () => $this->estimates->send($this->admin, $draft),
        fn () => $this->estimates->revise($this->admin, $sent),
        fn () => $this->estimates->cancel($this->admin, $sent),
    ] as $action) {
        expect($action)->toThrow(EstimateException::class, 'Estimate not found.');
    }

    expect($draft->refresh()->status)->toBe(EstimateStatus::Draft)
        ->and($sent->refresh()->status)->toBe(EstimateStatus::Sent)
        ->and(Estimate::count())->toBe(2);
});

test('organization A cannot access organization B line items, customers or conversations', function () {
    [$otherAdmin, $otherCustomer, $otherConversation] = estimateBusiness('Houston Plumbing');
    draftEstimate($otherAdmin, $otherCustomer, ['items' => [['description' => 'Secret line item', 'quantity' => '1', 'unit_price' => '999']]]);

    // A customer or conversation ID from another organization is rejected.
    expect(fn () => draftEstimate($this->admin, $otherCustomer))->toThrow(EstimateException::class, 'Choose a customer.')
        ->and(fn () => draftEstimate($this->admin, $this->john, ['conversation_id' => (string) $otherConversation->id]))->toThrow(EstimateException::class, 'conversation');

    // Even through the form's query string.
    Livewire::withQueryParams(['customer' => $otherCustomer->id, 'conversation' => $otherConversation->id])
        ->actingAs($this->admin)->test(EstimateForm::class)
        ->assertSet('customerId', '')->assertSet('conversationId', '')
        ->set('customerSearch', 'John')->assertDontSee('Secret line item');

    Livewire::actingAs($this->admin)->test(EstimateForm::class)->call('selectCustomer', $otherCustomer->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test(EstimateIndex::class)->assertDontSee('Secret line item');
});

test('a conversation from another customer cannot be linked', function () {
    $sarah = Customer::factory()->for($this->admin->organization)->create(['name' => 'Sarah Wilson', 'email' => 'sarah@example.com']);

    expect(fn () => draftEstimate($this->admin, $sarah, ['conversation_id' => (string) $this->conversation->id]))
        ->toThrow(EstimateException::class, 'That conversation does not belong to this customer.');
});

test('guests are redirected and people without an organization are blocked', function () {
    $estimate = draftEstimate($this->admin, $this->john);
    $this->get(route('estimates.index'))->assertRedirect();
    $this->get(route('estimates.show', $estimate->id))->assertRedirect();

    $loner = User::factory()->create(['organization_id' => null]);
    $this->actingAs($loner)->get(route('estimates.index'))->assertForbidden();
});

// The builder

test('an estimate can be built in the form and saved as a draft', function () {
    $page = Livewire::withQueryParams(['customer' => $this->john->id])->actingAs($this->admin)->test(EstimateForm::class)
        ->assertSet('customerId', (string) $this->john->id)
        ->assertSee('John Smith')
        ->set('title', 'AC Installation')
        ->set('items.0.description', 'AC Installation')
        ->set('items.0.unit_price', '2500')
        ->call('addItem')
        ->set('items.1.description', 'Thermostat')
        ->set('items.1.unit_price', '250')
        ->set('taxRate', '0');

    // Totals are shown while typing (calculated on the server).
    $page->assertSeeInOrder(['Subtotal', '$2,750.00', 'Discount', 'Tax', 'Total', '$2,750.00']);

    $page->call('save')->assertHasNoErrors()->assertRedirect(route('estimates.show', Estimate::sole()->id));

    $estimate = Estimate::sole();
    expect($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->total)->toBe('2750.00')
        ->and($estimate->items()->count())->toBe(2);
});

test('the form shows inline validation errors', function () {
    Livewire::actingAs($this->admin)->test(EstimateForm::class)
        ->set('items.0.description', 'AC')
        ->set('items.0.unit_price', 'abc')
        ->set('discountType', 'fixed')
        ->set('discountValue', '5000')
        ->call('save')
        ->assertHasErrors(['customerId', 'title', 'items.0.unit_price']);

    expect(Estimate::count())->toBe(0);
});

test('items can be added and removed', function () {
    Livewire::actingAs($this->admin)->test(EstimateForm::class)
        ->call('addItem')->call('addItem')
        ->assertCount('items', 3)
        ->call('removeItem', 1)
        ->assertCount('items', 2)
        ->call('removeItem', 0)->call('removeItem', 0)
        ->assertCount('items', 1); // always at least one row
});

test('"Save & Send" saves and sends', function () {
    Livewire::withQueryParams(['conversation' => $this->conversation->id])->actingAs($this->admin)->test(EstimateForm::class)
        ->assertSet('customerId', (string) $this->john->id)
        ->set('title', 'AC Installation')
        ->set('items.0.description', 'AC Installation')
        ->set('items.0.unit_price', '2500')
        ->call('saveAndSend')
        ->assertRedirect();

    $estimate = Estimate::sole();
    expect($estimate->status)->toBe(EstimateStatus::Sent)
        ->and($estimate->conversation_id)->toBe($this->conversation->id)
        ->and($this->provider->sent)->toHaveCount(1);
});

test('a sent estimate opens read-only: editing redirects to the estimate', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    Livewire::actingAs($this->admin)->test(EstimateForm::class, ['estimateId' => $estimate->id])
        ->assertRedirect(route('estimates.show', $estimate->id));
});

test('the estimate page shows the document, status and activity', function () {
    $estimate = sentEstimate($this->admin, $this->john, ['notes' => 'Includes removal of the old unit.']);
    $this->estimates->recordView($estimate);

    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $estimate->id])
        ->assertSee('EST-1001')->assertSee('AC Installation')->assertSee('Viewed')
        ->assertSeeInOrder(['AC Installation', '1 × $2,500.00', '$2,500.00', 'Thermostat', '1 × $250.00', '$250.00'])
        ->assertSeeInOrder(['Subtotal', '$2,750.00', 'Discount', '$0.00', 'Tax', '$0.00', 'Total', '$2,750.00'])
        ->assertSee('Includes removal of the old unit.')
        ->assertSee('john@example.com')->assertSee('View Customer')
        ->assertSeeInOrder(['Customer viewed estimate EST-1001', 'Estimate EST-1001 sent ($2,750.00) to john@example.com from sales@example.com', 'Estimate EST-1001 created ($2,750.00) by Mohsin'])
        ->assertSee('Revise Estimate')->assertDontSee('Send Estimate');
});

test('the estimate page sends a draft and shows a send failure', function () {
    $this->provider->sendFailures = [EmailProviderException::rejected('Inactive recipient')];
    $estimate = draftEstimate($this->admin, $this->john);

    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $estimate->id])
        ->assertSee('Send Estimate')
        ->call('send');

    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $estimate->id])
        ->assertSee('Unable to send estimate. Please try again.')
        ->assertSee('Try Again')
        ->call('send');

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent);
});

test('empty states', function () {
    Livewire::actingAs($this->admin)->test(EstimateIndex::class)->assertSee('No estimates yet.')->assertSee('Create Estimate');
    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->john->id])->assertSee("This customer doesn't have any estimates yet.")->assertSee('Create Estimate');

    $draft = draftEstimate($this->admin, $this->john, ['items' => []]);
    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $draft->id])->assertSee('Add at least one item before sending this estimate.');
});

// List, filters and search

test('the list shows number, customer, title, total, status and dates', function () {
    $sent = sentEstimate($this->admin, $this->john);

    Livewire::actingAs($this->admin)->test(EstimateIndex::class)
        ->assertSeeInOrder(['EST-1001', 'Sent', 'John Smith', 'AC Installation', 'Created Oct 3, 2026', 'Sent Oct 3', 'Valid until Nov 2', '$2,750.00']);
});

test('estimates can be filtered and searched in the database', function () {
    $sarah = Customer::factory()->for($this->admin->organization)->create(['name' => 'Sarah Wilson', 'email' => 'sarah@wilson.example']);
    $acceptedJohn = sentEstimate($this->admin, $this->john);
    $this->estimates->accept($acceptedJohn);
    $sarahRepair = sentEstimate($this->admin, $sarah, ['title' => 'HVAC Repair', 'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '1250']]]);
    $draft = draftEstimate($this->admin, $this->john, ['title' => 'Duct cleaning', 'items' => [['description' => 'Ducts', 'quantity' => '1', 'unit_price' => '400']]]);

    $titles = fn (array $filters) => Livewire::actingAs($this->admin)->test(EstimateIndex::class, [])->set($filters)->viewData('estimates')->pluck('title')->sort()->values()->all();

    expect($titles(['status' => 'accepted']))->toBe(['AC Installation'])
        ->and($titles(['status' => 'awaiting']))->toBe(['HVAC Repair'])
        ->and($titles(['status' => 'draft']))->toBe(['Duct cleaning'])
        ->and($titles(['customer' => (string) $sarah->id]))->toBe(['HVAC Repair'])
        ->and($titles(['min' => '1000', 'max' => '2000']))->toBe(['HVAC Repair'])
        ->and($titles(['search' => 'EST-1002']))->toBe(['HVAC Repair'])
        ->and($titles(['search' => 'sarah@wilson']))->toBe(['HVAC Repair'])
        ->and($titles(['search' => 'john smith']))->toBe(['AC Installation', 'Duct cleaning'])
        ->and($titles(['search' => 'duct']))->toBe(['Duct cleaning'])
        ->and($titles(['from' => '2026-10-04']))->toBe([])
        ->and($titles(['from' => '2026-10-03', 'to' => '2026-10-03']))->toHaveCount(3);
});

// Follow-ups

test('a follow-up can be scheduled from the estimate, pre-filled and linked', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    Livewire::actingAs($this->admin)->test(ShowEstimate::class, ['estimateId' => $estimate->id])
        ->call('openScheduleForm')
        ->assertSet('scheduleNotes', 'Follow up on Estimate EST-1001')
        ->call('scheduleFollowUp')
        ->assertHasNoErrors()
        ->assertSee('Follow up on Estimate EST-1001');

    $followUp = FollowUp::sole();
    expect($followUp->estimate_id)->toBe($estimate->id)
        ->and($followUp->customer_id)->toBe($this->john->id)
        ->and($followUp->conversation_id)->toBe($estimate->conversation_id)
        ->and($followUp->notes)->toBe('Follow up on Estimate EST-1001')
        ->and($estimate->followUps()->count())->toBe(1);

    expect(app(TimelineService::class)->forEstimate($estimate)->pluck('title')->all())->toContain('Follow-up scheduled');
});

test('a follow-up cannot be linked to another customer\'s estimate', function () {
    $sarah = Customer::factory()->for($this->admin->organization)->create(['name' => 'Sarah Wilson', 'email' => 'sarah@example.com']);
    $estimate = draftEstimate($this->admin, $this->john);

    expect(fn () => app(FollowUpService::class)->scheduleManual($this->admin, $sarah, now()->addDay(), null, null, null, $estimate))
        ->toThrow(InvalidFollowUpException::class, 'estimate');
});

// Customer and conversation pages

test('the customer page lists estimates and opens them', function () {
    $sent = sentEstimate($this->admin, $this->john);
    $this->estimates->accept($sent);
    $draft = draftEstimate($this->admin, $this->john, ['title' => 'HVAC Repair', 'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '1250']]]);

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->john->id])
        ->assertSeeInOrder(['Estimates', 'EST-1002', 'HVAC Repair', '$1,250.00', 'Draft', 'EST-1001', 'AC Installation', '$2,750.00', 'Accepted'])
        ->assertSee(route('estimates.show', $sent->id))
        ->assertSee(route('estimates.create', ['customer' => $this->john->id]));

    $this->actingAs($this->admin)->get(route('estimates.show', $sent->id))->assertOk()->assertSee('EST-1001');
});

test('the conversation page shows the linked estimate', function () {
    $estimate = sentEstimate($this->admin, $this->john, ['conversation_id' => (string) $this->conversation->id]);

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->assertSeeInOrder(['Estimate', 'EST-1001', '$2,750.00', 'AC Installation', 'Sent', 'View Estimate'])
        ->assertSee(route('estimates.show', $estimate->id));
});

test('estimate activity appears on the conversation and customer timelines', function () {
    $estimate = sentEstimate($this->admin, $this->john, ['conversation_id' => (string) $this->conversation->id]);
    $this->get($estimate->publicUrl());
    $this->post($estimate->publicUrl().'/accept');

    $titles = app(TimelineService::class)->build($this->john, $this->conversation->id)->pluck('title')->all();
    expect($titles)->toContain('Customer accepted estimate EST-1001 ($2,750.00)')
        ->and($titles)->toContain('Customer viewed estimate EST-1001')
        ->and($titles)->toContain('Estimate EST-1001 sent ($2,750.00) to john@example.com from sales@example.com')
        ->and($titles)->toContain('Estimate email sent');

    // Created before it had a conversation: still on the customer's timeline.
    $draft = draftEstimate($this->admin, $this->john);
    expect(app(TimelineService::class)->build($this->john)->pluck('title')->all())->toContain("Estimate {$draft->displayNumber()} created (\$2,750.00) by Mohsin");

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
        ->assertSee('Customer accepted estimate EST-1001');
});

// Dashboard

test('the dashboard shows estimates sent today, awaiting customer and accepted today', function () {
    sentEstimate($this->admin, $this->john);
    $viewed = sentEstimate($this->admin, $this->john);
    $this->estimates->recordView($viewed);
    $accepted = sentEstimate($this->admin, $this->john);
    $this->estimates->accept($accepted);
    draftEstimate($this->admin, $this->john);

    // Sent yesterday, still awaiting.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 15:00:00', 'UTC'));
    sentEstimate($this->admin, $this->john);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));

    // Another organization's estimates don't count.
    [$otherAdmin, $otherCustomer] = estimateBusiness('Houston Plumbing');
    sentEstimate($otherAdmin, $otherCustomer);

    $snapshot = app(DashboardService::class)->snapshot($this->admin);
    expect($snapshot->estimates)->toBe(['sent_today' => 3, 'awaiting' => 3, 'accepted_today' => 1]);

    Livewire::withoutLazyLoading()->actingAs($this->admin)->test(Dashboard::class)
        ->assertSeeInOrder(['Estimates', 'Sent today', '3', 'Awaiting customer', '3', 'Accepted today', '1']);
});
