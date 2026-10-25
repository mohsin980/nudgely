<?php

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationStatus;
use App\Enums\EstimateStatus;
use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Jobs\SendEmailJob;
use App\Livewire\Estimates\EstimateImportWizard;
use App\Models\Automation;
use App\Models\Estimate;
use App\Models\EstimateImport;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Estimates\Import\EstimateImportService;
use App\Services\Estimates\Import\ImportColumns;
use App\Services\Estimates\Import\ImportFollowUpActivation;
use App\Services\FollowUps\FollowUpProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    [$this->admin] = estimateBusiness();
    $this->imports = app(EstimateImportService::class);
    $this->activation = app(ImportFollowUpActivation::class);
});

const FU_HEADER = "Estimate ID,Customer name,Customer email,Amount,Estimate date,Expiration date,Status,Description\n";

/** Upload, match columns, check, and import a CSV in one go (the queue is faked, so the job is run directly). */
function fuImport(User $owner, string $csv): EstimateImport
{
    $imports = app(EstimateImportService::class);
    $import = $imports->upload($owner, UploadedFile::fake()->createWithContent('estimates.csv', $csv));
    $import = $imports->saveMapping($owner, $import, ImportColumns::suggest($import->summary['file']['headers']));
    $imports->start($owner, $import, true);
    $imports->process($import->refresh());

    return $import->refresh();
}

/** An active automation whose email follow-up steps set the sequence (delay in days, plus the message). */
function fuSequence(Organization $organization, array $delays = [1, 3, 7], AutomationStatus $status = AutomationStatus::Active): Automation
{
    $automation = Automation::factory()->create(['organization_id' => $organization->id, 'name' => 'Estimate follow-ups', 'status' => $status]);

    foreach ($delays as $i => $days) {
        $automation->actions()->create([
            'type' => AutomationActionType::ScheduleFollowUp,
            'configuration' => ['kind' => 'email', 'delay_days' => $days, 'subject' => 'Following up on your estimate', 'body' => 'Hi {{customer.first_name}}, just checking in.'],
            'sort_order' => $i,
        ]);
    }

    return $automation;
}

test('only estimates the file says were sent are eligible, and each exclusion has a reason', function () {
    fuSequence($this->admin->organization);
    fuImport($this->admin, FU_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,sent,Sent one\n"
        ."EST-2,Mike Brown,mike@example.com,200,,,draft,Never sent\n"
        ."EST-3,Sarah Jones,,300,,,viewed,No email\n"
        ."EST-4,Tom Lee,tom@example.com,400,2020-01-01,2020-02-01,sent,Expired\n"
        ."EST-5,Ann Price,ann@example.com,500,,,accepted,Already accepted\n");

    $plan = $this->activation->plan(EstimateImport::query()->sole());

    expect($plan['eligible']->pluck('external_reference')->all())->toBe(['EST-1'])
        ->and($plan['excluded'])->toMatchArray(['not_confirmed_sent' => 2, 'no_email' => 1, 'expired' => 1]);
});

test('an opt-out recorded after the import is rechecked at activation, not trusted from the page', function () {
    fuSequence($this->admin->organization);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");
    Estimate::query()->sole()->customer->forceFill(['email_opted_out_at' => now()])->save();

    expect(fn () => $this->activation->activate($this->admin, $import, true))->toThrow(ValidationException::class, 'No estimates are eligible');
    expect(FollowUp::query()->count())->toBe(0);
});

test('activation schedules one follow-up per step for each eligible estimate, and sends nothing', function () {
    fuSequence($this->admin->organization, [1, 3, 7]);
    $import = fuImport($this->admin, FU_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n"
        ."EST-2,Mike Brown,mike@example.com,200,,,viewed,Furnace\n");

    $scheduled = $this->activation->activate($this->admin, $import, true);

    expect($scheduled)->toBe(6);
    $followUps = FollowUp::query()->orderBy('id')->get();
    expect($followUps)->toHaveCount(6)
        ->and($followUps->pluck('status')->unique()->all())->toBe([FollowUpStatus::Pending])
        ->and($followUps->pluck('estimate_id')->unique()->count())->toBe(2)
        ->and($followUps->pluck('automation_action_id')->unique()->count())->toBe(3)
        ->and($followUps->first()->due_at->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()))->toBeTrue();

    // The estimates stay drafts: activating follow-ups is not sending them.
    expect(Estimate::query()->pluck('status')->unique()->all())->toBe([EstimateStatus::Draft])
        ->and(Estimate::query()->whereNotNull('follow_up_activated_at')->count())->toBe(2);

    Queue::assertNotPushed(SendEmailJob::class);
    expect(Message::query()->count())->toBe(0);
});

test('activation is idempotent: repeating it schedules nothing new', function () {
    fuSequence($this->admin->organization, [2]);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");

    $this->activation->activate($this->admin, $import, true);
    expect(fn () => $this->activation->activate($this->admin, $import, true))->toThrow(ValidationException::class);
    expect(FollowUp::query()->count())->toBe(1);
});

test('a follow-up scheduled from an import is linked to its conversation, so the engine does not skip it', function () {
    fuSequence($this->admin->organization, [1]);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");
    $this->activation->activate($this->admin, $import, true);

    $followUp = FollowUp::query()->sole();

    expect(app(FollowUpProcessor::class)->stopReason($followUp))->toBeNull()
        ->and($followUp->conversation->customer_id)->toBe($followUp->customer_id);
});

test('activation needs explicit confirmation', function () {
    fuSequence($this->admin->organization);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");

    expect(fn () => $this->activation->activate($this->admin, $import, false))->toThrow(ValidationException::class, 'already sent');
    expect(FollowUp::query()->count())->toBe(0);
});

test('without an active follow-up automation, activation is blocked with the reason and nothing is scheduled', function () {
    fuSequence($this->admin->organization, [1], AutomationStatus::Draft);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");

    expect($this->activation->plan($import)['blocked'])->toContain('Turn on a follow-up automation first');
    expect(fn () => $this->activation->activate($this->admin, $import, true))->toThrow(ValidationException::class, 'Turn on a follow-up automation');
    expect(FollowUp::query()->count())->toBe(0);
});

test('an automation whose follow-up steps are incomplete cannot start a sequence', function () {
    $automation = Automation::factory()->active()->create(['organization_id' => $this->admin->organization_id, 'name' => 'Incomplete']);
    $automation->actions()->create(['type' => AutomationActionType::ScheduleFollowUp, 'configuration' => ['kind' => 'email', 'delay_days' => 2], 'sort_order' => 0]);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");

    expect($this->activation->plan($import)['blocked'])->toContain("\"{$automation->name}\" has no email follow-up steps");
});

test('another business cannot activate or see another business\'s import', function () {
    fuSequence($this->admin->organization);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");
    [$stranger] = estimateBusiness('Other Plumbing');

    expect(fn () => $this->activation->activate($stranger, $import, true))->toThrow(HttpException::class);
    Livewire::actingAs($stranger)->test(EstimateImportWizard::class, ['importId' => $import->id])->assertStatus(404);
    expect(FollowUp::query()->count())->toBe(0);
});

test('the review page shows what activation will do, the reasons for exclusions, and a confirmation', function () {
    fuSequence($this->admin->organization, [1, 4]);
    $import = fuImport($this->admin, FU_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n"
        ."EST-2,Mike Brown,,200,,,sent,Furnace\n");

    Livewire::actingAs($this->admin)->test(EstimateImportWizard::class, ['importId' => $import->id])
        ->assertSee('Start follow-ups for estimates already sent')
        ->assertSee('No email address')
        ->assertSee('after 1 day, after 4 days')
        ->set('followUpsConfirmed', true)
        ->call('activateFollowUps')
        ->assertSee('2 follow-up messages scheduled')
        ->assertSee('Nothing was emailed');

    expect(FollowUp::query()->count())->toBe(2);
});

test('a follow-up does not send once its estimate has passed its valid-until date, even if it was scheduled in time', function () {
    fuSequence($this->admin->organization, [1]);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,2099-01-01,sent,AC\n");
    $this->activation->activate($this->admin, $import, true);

    // The date passes after the follow-up was scheduled; a draft never expires on its own, so the send must check it.
    Estimate::query()->sole()->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();
    $followUp = FollowUp::query()->sole();
    $followUp->forceFill(['status' => FollowUpStatus::Due, 'due_at' => now()->subMinute()])->save();

    expect(app(FollowUpProcessor::class)->stopReason($followUp))->toBe(FollowUpSkipReason::EstimateClosed);
    expect(app(FollowUpProcessor::class)->process($followUp->id))->toBe(FollowUpProcessor::SKIPPED);
    expect($followUp->refresh()->status)->toBe(FollowUpStatus::Skipped);
    Queue::assertNotPushed(SendEmailJob::class);
});

test('activation is blocked, with the reason, while automations are turned off for the business', function () {
    fuSequence($this->admin->organization);
    $import = fuImport($this->admin, FU_HEADER."EST-1,Jane Smith,jane@example.com,100,,,sent,AC\n");
    $this->admin->organization->forceFill(['automations_enabled' => false])->save();
    $import->refresh();

    expect($this->activation->plan($import)['blocked'])->toContain('Automations are turned off');
    expect(fn () => $this->activation->activate($this->admin, $import, true))->toThrow(ValidationException::class, 'Automations are turned off');
    expect(FollowUp::query()->count())->toBe(0);
});
