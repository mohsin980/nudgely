<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\FollowUpStatus;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\FollowUp;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * complete_follow_up / cancel_follow_up: closes the open follow-ups the event is about —
 * the trigger's follow-up, else the estimate's, else the conversation's. Follow-ups that are
 * already closed are left alone, so retries are harmless.
 */
abstract class CloseFollowUpsAction implements AutomationActionInterface
{
    public function __construct(private readonly FollowUpService $followUps) {}

    abstract protected function status(): FollowUpStatus;

    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $automation = Automation::query()->where('organization_id', $context->organizationId)->findOrFail($action->automation_id);
        $ids = $this->targets($context);

        if ($ids->isEmpty()) {
            return AutomationActionResult::skipped('No open follow-up to '.$this->verb().'.', ['reason' => 'nothing_open']);
        }

        $closed = $ids->filter(fn (int $id) => $this->followUps->closeByAutomation($id, $this->status(), $automation))->count();
        $past = $this->status() === FollowUpStatus::Completed ? 'completed' : 'cancelled';

        return $closed === 0
            ? AutomationActionResult::skipped('Follow-up already closed.', ['reason' => 'already_closed'])
            : AutomationActionResult::completed(ucfirst(Str::plural('follow-up', $closed)).' '.$past.": {$closed}.", ['follow_up_ids' => $ids->all()]);
    }

    /**
     * @return Collection<int, int>
     */
    private function targets(AutomationContext $context): Collection
    {
        $open = FollowUp::query()->forOrganization($context->organizationId)->open();

        return match (true) {
            $context->followUpId !== null => $open->whereKey($context->followUpId)->pluck('id'),
            $context->estimateId !== null => $open->where('estimate_id', $context->estimateId)->pluck('id'),
            $context->conversationId !== null => $open->where('conversation_id', $context->conversationId)->pluck('id'),
            default => collect(),
        };
    }

    private function verb(): string
    {
        return $this->status() === FollowUpStatus::Completed ? 'complete' : 'cancel';
    }
}
