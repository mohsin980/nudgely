<?php

use App\Enums\LeadStage;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\ShowCustomer;
use App\Livewire\Dashboard;
use App\Livewire\Inbox\ShowConversation;
use App\Models\AssistantChat;
use App\Models\AssistantProfile;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadOutcomeReminders;
use App\Services\Leads\LeadStageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->team = teamBusiness();
    ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff, 'customer' => $this->customer] = $this->team;
});

function notificationsFor(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->orderBy('created_at')->pluck('data')->map(fn ($data) => json_decode($data, true)['message'] ?? '')->all();
}

// ─── Assigning ──────────────────────────────────────────────────────────────────────────

test('the owner gives a lead to a team member, who is notified', function () {
    Livewire::actingAs($this->owner)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->assertSee('Handled by:')
        ->call('assignLead', (string) $this->staff->id);

    expect($this->customer->fresh()->assigned_to)->toBe($this->staff->id)
        ->and(notificationsFor($this->staff))->toBe(['John Smith gave you a lead: Pat Customer.']);
});

test('staff can take an unassigned lead and give it back, but not give leads to others', function () {
    $assignments = app(LeadAssignmentService::class);

    $assignments->assign($this->staff, $this->customer, $this->staff);
    expect($this->customer->fresh()->assigned_to)->toBe($this->staff->id)
        ->and(fn () => $assignments->assign($this->staff, $this->customer->fresh(), $this->manager))->toThrow(AuthorizationException::class);

    $assignments->assign($this->staff, $this->customer->fresh(), null);
    expect($this->customer->fresh()->assigned_to)->toBeNull();

    $assignments->assign($this->owner, $this->customer->fresh(), $this->manager);
    expect(fn () => $assignments->assign($this->staff, $this->customer->fresh(), $this->staff))->toThrow(AuthorizationException::class);
});

test('a lead can only be given to an active member of the same business', function () {
    $outsider = teamBusiness('Other Co')['staff'];

    expect(fn () => app(LeadAssignmentService::class)->assign($this->owner, $this->customer, $outsider))->toThrow(ValidationException::class);

    Livewire::actingAs($this->owner)->test(ShowCustomer::class, ['customerId' => $this->customer->id])
        ->call('assignLead', (string) $outsider->id)->assertStatus(422);
    expect($this->customer->fresh()->assigned_to)->toBeNull();
});

test('the customers list shows who handles each lead and filters "Handled by: Me"', function () {
    $other = Customer::factory()->for($this->team['organization'])->create(['name' => 'Zara Unassigned']);
    app(LeadAssignmentService::class)->assign($this->owner, $this->customer, $this->staff);

    Livewire::actingAs($this->staff)->test(CustomerIndex::class)
        ->assertSee('Mike Johnson')
        ->set('handler', 'me')->assertSee('Pat Customer')->assertDontSee('Zara Unassigned')
        ->set('handler', 'none')->assertSee('Zara Unassigned')->assertDontSee('Pat Customer');
});

test('after the AI assistant hands over, the owner can give the lead to someone from the chat', function () {
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->customer->organization_id]);
    (new AssistantChat)->forceFill(['organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'customer_id' => $this->customer->id,
        'status' => 'handed_over', 'replies' => 2, 'handover_reason' => 'Wants a site visit.', 'handed_over_at' => now()])->save();

    Livewire::actingAs($this->owner)->test(ShowConversation::class, ['conversationId' => $conversation->id])
        ->assertSee('Give this lead to')
        ->call('assignLead', (string) $this->manager->id)
        ->assertSee('Pat Customer is now handled by Sarah Wilson.');

    Livewire::actingAs($this->staff)->test(ShowConversation::class, ['conversationId' => $conversation->id])->assertDontSee('Give this lead to');
});

// ─── Won / Lost reminders ───────────────────────────────────────────────────────────────

test('a quiet qualified lead reminds the person handling it to record the outcome, weekly, at most three times', function () {
    app(LeadStageService::class)->advance($this->customer->organization_id, $this->customer->id, LeadStage::Qualified, 'assistant_qualified');
    app(LeadAssignmentService::class)->assign($this->owner, $this->customer, $this->staff);
    $reminders = app(LeadOutcomeReminders::class);

    $this->travel(6)->days();
    expect($reminders->send())->toBe(0);

    $this->travel(2)->days();
    expect($reminders->send())->toBe(1)->and($reminders->send())->toBe(0);
    expect(collect(notificationsFor($this->staff))->last())->toBe('Did Pat Customer go ahead? They have been Qualified for 8 days: mark the lead Won or Lost.')
        ->and(notificationsFor($this->owner))->toBe([]);

    foreach (range(1, 4) as $week) {
        $this->travel(7)->days();
        $reminders->send();
    }

    expect($this->customer->fresh()->outcome_reminders)->toBe(3)
        ->and(collect(notificationsFor($this->staff))->filter(fn ($m) => str_starts_with($m, 'Did Pat'))->count())->toBe(3);
});

test('leads nobody handles remind the owner and managers; won, lost and new leads are never reminded', function () {
    $won = Customer::factory()->for($this->team['organization'])->create(['name' => 'Won Lead']);
    app(LeadStageService::class)->advance($this->customer->organization_id, $this->customer->id, LeadStage::Quoted, 'estimate_sent');
    app(LeadStageService::class)->advance($won->organization_id, $won->id, LeadStage::Won, 'estimate_accepted');
    $this->travel(8)->days();

    expect(app(LeadOutcomeReminders::class)->send())->toBe(1);
    expect(notificationsFor($this->owner))->toHaveCount(1)->and(notificationsFor($this->manager))->toHaveCount(1)->and(notificationsFor($this->staff))->toBe([]);
});

test('changing the stage starts the reminders again', function () {
    app(LeadStageService::class)->advance($this->customer->organization_id, $this->customer->id, LeadStage::Qualified, 'assistant_qualified');
    $this->travel(8)->days();
    app(LeadOutcomeReminders::class)->send();

    app(LeadStageService::class)->advance($this->customer->organization_id, $this->customer->id, LeadStage::Quoted, 'estimate_sent');

    expect($this->customer->fresh())->outcome_reminders->toBe(0)->outcome_reminded_at->toBeNull();
    expect(app(LeadOutcomeReminders::class)->send())->toBe(0); // quoted just now
});

test('the reminder runs every day', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('leads:remind-outcomes');
});

// ─── Dashboard ──────────────────────────────────────────────────────────────────────────

test('the dashboard shows the AI assistant\'s month and leads waiting for Won or Lost', function () {
    $profile = new AssistantProfile;
    $profile->forceFill(['organization_id' => $this->team['organization']->id, 'assistant_name' => 'Sara', 'languages' => 'en', 'about' => 'We sell plots.', 'enabled' => true])->save();
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->customer->organization_id]);
    (new AssistantChat)->forceFill(['organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'customer_id' => $this->customer->id,
        'status' => 'handed_over', 'replies' => 4, 'qualified_at' => now(), 'handed_over_at' => now()])->save();
    app(LeadStageService::class)->advance($this->customer->organization_id, $this->customer->id, LeadStage::Qualified, 'assistant_qualified');
    $this->travel(8)->days();

    Livewire::withoutLazyLoading()->actingAs($this->owner)->test(Dashboard::class)
        ->assertSee('Sara (AI assistant)')
        ->assertSeeHtml('data-assistant-stat="qualified">1<')
        ->assertSee('1 lead is waiting for Won or Lost');
});
