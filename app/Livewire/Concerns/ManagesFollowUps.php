<?php

namespace App\Livewire\Concerns;

use App\Enums\FollowUpCancelReason;
use App\Exceptions\FollowUps\InvalidFollowUpException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpProcessor;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Follow-up actions shared by the follow-ups page, the customer page and the conversation page.
 *
 * Only form state lives here; FollowUpService and FollowUpProcessor do the work. Follow-ups,
 * customers, conversations and assignees are always looked up inside the signed-in user's
 * organization, so IDs from the browser can't reach another tenant.
 */
trait ManagesFollowUps
{
    #[Locked]
    public ?int $activeFollowUpId = null;

    #[Locked]
    public string $followUpForm = '';

    public string $rescheduleDate = '';

    public string $rescheduleTime = '';

    public string $cancelReason = 'manually_cancelled';

    public string $cancelNote = '';

    public string $completionNotes = '';

    public bool $showScheduleForm = false;

    public string $scheduleCustomerId = '';

    public string $scheduleDate = '';

    public string $scheduleTime = '10:00';

    public string $scheduleNotes = '';

    public string $scheduleAssignee = '';

    public ?string $followUpMessage = null;

    public string $followUpMessageType = 'success';

    /**
     * The customer (and optional conversation and estimate) a new follow-up is for on this page.
     *
     * @return array{0: Customer, 1: ?Conversation, 2?: ?Estimate}
     */
    abstract protected function followUpTarget(): array;

    /**
     * Notes a new follow-up starts with; pages override (e.g. "Follow up on Estimate EST-1024").
     */
    protected function defaultFollowUpNotes(): string
    {
        return '';
    }

    public function openFollowUpForm(int $followUpId, string $form): void
    {
        abort_unless(in_array($form, ['complete', 'reschedule', 'cancel'], true), 422);
        $followUp = $this->findFollowUp($followUpId);

        $this->resetErrorBag();
        $this->activeFollowUpId = $followUp->id;
        $this->followUpForm = $form;
        $local = $this->organization()->localTime(max($followUp->due_at, now()->addHour()));
        $this->rescheduleDate = $local->format('Y-m-d');
        $this->rescheduleTime = $local->format('H:i');
        $this->cancelReason = FollowUpCancelReason::ManuallyCancelled->value;
        $this->cancelNote = '';
        $this->completionNotes = '';
    }

    public function closeFollowUpForm(): void
    {
        $this->activeFollowUpId = null;
        $this->followUpForm = '';
        $this->resetErrorBag();
    }

    public function completeFollowUp(FollowUpService $followUps): void
    {
        $this->runFollowUpAction(fn (FollowUp $followUp) => $followUps->complete($followUp, $this->actor(), $this->completionNotes), 'Follow-up completed.');
    }

    public function rescheduleFollowUp(FollowUpService $followUps): void
    {
        $this->runFollowUpAction(function (FollowUp $followUp) use ($followUps) {
            $dueAt = $followUps->parseLocal($this->organization(), $this->rescheduleDate, $this->rescheduleTime);
            $followUps->reschedule($followUp, $this->actor(), $dueAt);
        }, 'Follow-up rescheduled.');
    }

    public function cancelFollowUp(FollowUpService $followUps): void
    {
        $reason = FollowUpCancelReason::tryFrom($this->cancelReason);

        if ($reason === null) {
            $this->addError('cancelReason', 'Choose a reason.');

            return;
        }

        if ($reason === FollowUpCancelReason::Other && trim($this->cancelNote) === '') {
            $this->addError('cancelNote', 'Tell us why it was cancelled.');

            return;
        }

        $this->runFollowUpAction(fn (FollowUp $followUp) => $followUps->cancel($followUp, $this->actor(), $reason, $this->cancelNote), 'Follow-up cancelled.');
    }

    /**
     * Approve and send a ready automated follow-up email.
     */
    public function sendFollowUp(int $followUpId, FollowUpProcessor $processor): void
    {
        $followUp = $this->findFollowUp($followUpId);

        try {
            [$outcome, $message] = $processor->sendNow($followUp, $this->actor());
        } catch (InvalidFollowUpException $e) {
            $this->flashFollowUp($e->getMessage(), 'error');

            return;
        }

        $this->flashFollowUp($message, $outcome === FollowUpProcessor::SENT ? 'success' : 'error');
        $this->followUpsChanged();
    }

    public function openScheduleForm(): void
    {
        $this->authorize('create', FollowUp::class);

        $this->resetErrorBag();
        $this->showScheduleForm = true;
        $this->scheduleDate = $this->organization()->localNow()->addDay()->format('Y-m-d');
        $this->scheduleTime = '10:00';
        $this->scheduleNotes = $this->defaultFollowUpNotes();
        $this->scheduleAssignee = (string) Auth::id();
    }

    public function scheduleFollowUp(FollowUpService $followUps): void
    {
        $this->authorize('create', FollowUp::class);
        $this->resetErrorBag();

        $assignee = $this->scheduleAssignee === '' ? null
            : (User::query()->where('organization_id', $this->organization()->id)->find((int) $this->scheduleAssignee) ?? abort(404));

        try {
            $target = $this->followUpTarget();
            [$customer, $conversation] = $target;
            $dueAt = $followUps->parseLocal($this->organization(), $this->scheduleDate, $this->scheduleTime);
            $followUps->scheduleManual($this->actor(), $customer, $dueAt, $this->scheduleNotes, $conversation, $assignee, $target[2] ?? null);
        } catch (InvalidFollowUpException $e) {
            $this->addError('schedule', $e->getMessage());

            return;
        }

        $this->showScheduleForm = false;
        $this->flashFollowUp('Follow-up scheduled.');
        $this->followUpsChanged();
    }

    /**
     * People a follow-up can be assigned to.
     *
     * @return Collection<int, string>
     */
    public function assignableUsers(): Collection
    {
        return User::query()->where('organization_id', $this->organization()->id)->orderBy('name')->pluck('name', 'id');
    }

    /**
     * Reset computed follow-up lists after a change; pages override to clear their caches.
     */
    protected function followUpsChanged(): void {}

    protected function organization(): Organization
    {
        return Auth::user()->organization ?? abort(403);
    }

    protected function actor(): User
    {
        return Auth::user();
    }

    protected function findFollowUp(int $followUpId): FollowUp
    {
        $followUp = FollowUp::query()->forOrganization($this->organization())->whereKey($followUpId)->first() ?? abort(404);
        $this->authorize('update', $followUp);

        return $followUp;
    }

    private function runFollowUpAction(\Closure $action, string $success): void
    {
        $followUp = $this->findFollowUp($this->activeFollowUpId ?? abort(404));

        try {
            $action($followUp);
        } catch (InvalidFollowUpException $e) {
            $this->addError('followUp', $e->getMessage());

            return;
        }

        $this->closeFollowUpForm();
        $this->flashFollowUp($success);
        $this->followUpsChanged();
    }

    private function flashFollowUp(string $message, string $type = 'success'): void
    {
        $this->followUpMessage = $message;
        $this->followUpMessageType = $type;
    }
}
