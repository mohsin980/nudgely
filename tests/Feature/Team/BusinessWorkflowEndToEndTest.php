<?php

use App\Enums\Automation\AutomationRunStatus;
use App\Enums\ClassificationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\EmailVerificationStatus;
use App\Enums\EstimateStatus;
use App\Enums\FollowUpStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Enums\Team\MemberStatus;
use App\Livewire\Automations\AutomationForm;
use App\Livewire\Automations\ShowAutomation;
use App\Livewire\Customers\CreateCustomerForm;
use App\Livewire\Dashboard;
use App\Livewire\Estimates\EstimateForm;
use App\Livewire\Settings\AutomationDefaults;
use App\Livewire\Settings\BusinessPreferences;
use App\Livewire\Settings\BusinessProfile;
use App\Livewire\Settings\EmailSettings;
use App\Livewire\Settings\TeamMembers;
use App\Livewire\Team\AcceptInvitation;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\MessageClassification;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\ReplyClassifierManager;
use App\Services\Email\ReplyRouteService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\Fakes\FakeReplyClassifier;

uses(PostmarkInboundPayloads::class);

/**
 * The whole business workflow, through the real pages and endpoints (only the email provider
 * and the AI model are faked):
 *
 * sign up → configure business → invite staff → connect email → add customer → create estimate
 * → send it → receive reply → AI classifies reply → automation runs → follow-up scheduled
 * → staff handles the task.
 */
test('a new business runs from sign-up to staff completing an automation task', function () {
    $this->withoutVite();
    $provider = fakeEmailProvider();
    $provider->verifies = true;
    config(['ai.classification.enabled' => true]);
    $this->configureInboundWebhook();
    $classifier = new FakeReplyClassifier;
    app(ReplyClassifierManager::class)->extend('openai', fn () => $classifier);

    // 1. Sign up: a business and its owner.
    $this->post('/register', [
        'business_name' => 'Dallas HVAC', 'name' => 'John Smith', 'email' => 'John@DallasHVAC.com',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('onboarding.show'));

    $owner = User::where('email', 'john@dallashvac.com')->sole();
    $organization = $owner->organization;
    expect($owner->role)->toBe(OrganizationRole::Owner)
        ->and($owner->status)->toBe(MemberStatus::Active)
        ->and($organization->name)->toBe('Dallas HVAC')
        ->and($organization->timezone())->toBe('America/Chicago');
    $this->assertAuthenticatedAs($owner);

    // 2. Configure the business.
    Livewire::actingAs($owner)->test(BusinessProfile::class)
        ->set('email', 'hello@dallashvac.com')->set('phone', '(214) 555-1234')->set('website', 'https://dallashvac.com')
        ->set('city', 'Dallas')->set('state', 'TX')->set('postalCode', '75201')
        ->call('save')->assertHasNoErrors();
    Livewire::actingAs($owner)->test(BusinessPreferences::class)
        ->set('timezone', 'America/Chicago')->set('currency', 'USD')->call('save')->assertHasNoErrors();

    // 3. Invite staff. No verified sender yet, so the owner gets the link to share.
    $team = Livewire::actingAs($owner)->test(TeamMembers::class)
        ->call('openInviteForm')->set('inviteName', 'Mike Johnson')->set('inviteEmail', 'mike@dallashvac.com')->set('inviteRole', 'staff')
        ->call('invite')->assertHasNoErrors();
    $link = $team->get('inviteLink');
    expect($link)->toMatch('~/invitations/[A-Za-z0-9]{64}$~');
    $token = basename($link);

    Auth::logout();
    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->assertSee('Join Dallas HVAC')->assertSee('Staff')
        ->set('password', 'mike-secure-pass-1')->set('passwordConfirmation', 'mike-secure-pass-1')
        ->call('accept')->assertHasNoErrors()->assertRedirect(route('dashboard'));
    $staff = User::where('email', 'mike@dallashvac.com')->sole();
    expect($staff->role)->toBe(OrganizationRole::Staff)->and($staff->organization_id)->toBe($organization->id);

    // Tasks from automations go to Mike by default.
    Livewire::actingAs($owner)->test(AutomationDefaults::class)->set('taskAssigneeId', (string) $staff->id)->call('save')->assertHasNoErrors();

    // 4. Connect email: add the sender, verify the domain, make it the default.
    $email = Livewire::actingAs($owner)->test(EmailSettings::class)
        ->call('create')->set('domain', 'dallashvac.com')->set('senderName', 'Dallas HVAC')->set('senderEmail', 'hello@dallashvac.com')
        ->call('save')->assertHasNoErrors();
    $connection = EmailConnection::where('organization_id', $organization->id)->sole();
    $email->call('startVerification', $connection->id)->call('checkVerification', $connection->id)->assertSet('statusMessage', 'Your domain is verified.');
    $email->call('setDefault', $connection->id);
    expect($connection->fresh()->verification_status)->toBe(EmailVerificationStatus::Verified)->and($connection->fresh()->is_default)->toBeTrue();

    // The owner builds the automation: customer reply (AI) → interested → follow-up + task.
    Livewire::actingAs($owner)->test(AutomationForm::class)
        ->set('name', 'Interested customer follow-up')->call('next')
        ->set('triggerType', 'customer_reply_classified')->call('next')
        ->call('addCondition')->set('conditions.0', ['type' => 'intent_equals', 'operator' => 'equals', 'value' => 'interested'])->call('next')
        ->set('actions', [
            ['type' => 'schedule_follow_up', 'requires_approval' => false, 'configuration' => ['kind' => 'reminder', 'delay_days' => '2', 'title' => 'Follow up with {{customer.name}} about {{conversation.subject}}']],
            ['type' => 'create_task', 'requires_approval' => false, 'configuration' => ['title' => 'Call {{customer.name}} to schedule the install', 'priority' => 'high', 'assign_to' => '']],
        ])->call('next')->assertSet('step', 5)
        ->call('saveDraft')->assertHasNoErrors();
    $automation = Automation::sole();
    Livewire::actingAs($owner)->test(ShowAutomation::class, ['automationId' => $automation->id])->call('activate')->assertHasNoErrors();

    // 5. Add a customer.
    Livewire::actingAs($owner)->test(CreateCustomerForm::class)
        ->set('first_name', 'Pat')->set('last_name', 'Garcia')->set('email', 'pat@example.com')->call('save')->assertHasNoErrors();
    $customer = Customer::where('organization_id', $organization->id)->sole();

    // 6–7. Create the estimate and send it.
    Livewire::withQueryParams(['customer' => $customer->id])->actingAs($owner)->test(EstimateForm::class)
        ->set('title', 'AC Installation')->set('items.0.description', 'AC Installation')->set('items.0.unit_price', '2500')
        ->call('saveAndSend')->assertHasNoErrors();
    $estimate = Estimate::sole();
    expect($estimate->status)->toBe(EstimateStatus::Sent)
        ->and(collect($provider->sent)->pluck('toEmail'))->toContain('pat@example.com');

    // 8–9. The customer replies by email; the AI classifies it as interested.
    $classifier->willReturn(CustomerReplyIntent::Interested, 0.93);
    $payload = $this->postmarkInbound(app(ReplyRouteService::class)->createFor($estimate->conversation), [
        'MessageID' => 'reply-pat-1', 'From' => 'pat@example.com', 'FromFull' => ['Email' => 'pat@example.com', 'Name' => 'Pat Garcia', 'MailboxHash' => ''],
        'TextBody' => 'Looks good, we are interested. What are the next steps?', 'StrippedTextReply' => 'Looks good, we are interested. What are the next steps?',
    ]);
    $this->postJson('/webhooks/email/inbound/postmark', $payload, $this->webhookAuth())->assertOk();

    $classification = MessageClassification::sole();
    expect($classification->intent)->toBe(CustomerReplyIntent::Interested)
        ->and($classification->status)->toBe(ClassificationStatus::Succeeded);

    // 10. The automation ran once and succeeded.
    $run = AutomationRun::where('automation_id', $automation->id)->sole();
    expect($run->status)->toBe(AutomationRunStatus::Completed)->and($run->customer_id)->toBe($customer->id);

    // 11. A follow-up is scheduled for two days out.
    $followUp = FollowUp::where('customer_id', $customer->id)->sole();
    expect($followUp->status)->toBe(FollowUpStatus::Pending)
        ->and($followUp->notes ?? $followUp->reason())->toContain('Pat Garcia')
        ->and($followUp->due_at->isSameDay(now()->addDays(2)))->toBeTrue();

    // …and a high-priority task for Mike, who is notified.
    $task = Task::where('customer_id', $customer->id)->sole();
    expect($task->title)->toBe('Call Pat Garcia to schedule the install')
        ->and($task->assigned_to)->toBe($staff->id)
        ->and($staff->notifications()->whereRaw("(data::jsonb ->> 'kind') = ?", ['task_assigned'])->count())->toBe(1);

    // 12. Mike signs in, sees the task on his dashboard and completes it.
    Auth::logout();
    $this->post('/login', ['email' => 'mike@dallashvac.com', 'password' => 'mike-secure-pass-1'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($staff);

    Livewire::withoutLazyLoading()->actingAs($staff)->test(Dashboard::class)
        ->assertSee('Call Pat Garcia to schedule the install')->assertSee('Assigned to Mike Johnson')
        ->call('completeTask', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and(Organization::count())->toBe(1);

    // Staff can do the work but not the owner's settings.
    $this->get('/settings/team')->assertForbidden();
    $this->get('/settings/business')->assertForbidden();
});
