<?php

namespace App\Services\Conversations;

use App\Enums\ClassificationStatus;
use App\Enums\FollowUpStatus;
use App\Models\AutomationRun;
use App\Models\ConversationEvent;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One timeline from existing records: messages, AI classifications (and corrections),
 * automation runs, follow-ups, tasks and conversation events. Nothing is recomputed or
 * re-run. Each source is read newest-first with a LIMIT, then merged, so the cost stays
 * the same however long the history is.
 */
class TimelineService
{
    /**
     * Newest first, for a customer (all their conversations) or a single conversation.
     *
     * @param  bool  $withMessages  The conversation page renders messages (and their AI insight) itself;
     * @param  bool  $withClassifications  it asks only for the other events.
     * @return Collection<int, TimelineEntry>
     */
    public function build(Customer $customer, ?int $conversationId = null, int $limit = 50, bool $withMessages = true, bool $withClassifications = true): Collection
    {
        $scope = fn ($query, string $table) => $query
            ->where("{$table}.organization_id", $customer->organization_id)
            ->when($conversationId, fn ($q) => $q->where("{$table}.conversation_id", $conversationId));
        $conversationIds = fn ($q) => $q->select('id')->from('conversations')->where('organization_id', $customer->organization_id)->where('customer_id', $customer->id);

        $entries = collect();

        if ($withMessages) {
            $messages = $scope(Message::query(), 'messages')
                ->whereIn('conversation_id', $conversationIds)
                ->whereIn('status', ['received', 'queued', 'sending', 'sent', 'failed'])
                ->select('id', 'conversation_id', 'direction', 'status', 'subject', 'received_at', 'sent_at', 'created_at', 'metadata')
                ->selectRaw('left(body_text, 300) as excerpt')
                ->latest('id')->limit($limit)->get();

            foreach ($messages as $m) {
                $inbound = $m->isInbound();
                $entries->push(new TimelineEntry(
                    at: $m->received_at ?? $m->sent_at ?? $m->created_at,
                    kind: $inbound ? 'customer' : 'business',
                    title: $inbound ? 'Customer replied' : ($m->status->value === 'failed' ? 'Email not delivered' : (($m->metadata['type'] ?? null) === 'follow_up' ? 'Follow-up email sent' : 'Business sent email')),
                    body: $this->excerpt($m->excerpt),
                    conversationId: $m->conversation_id,
                ));
            }
        }

        $classifications = ! $withClassifications ? collect() : $scope(MessageClassification::query(), 'message_classifications')
            ->whereIn('conversation_id', $conversationIds)
            ->where('status', ClassificationStatus::Succeeded)
            ->with('overrider:id,name')
            ->latest('id')->limit($limit)->get();

        foreach ($classifications as $c) {
            $entries->push(new TimelineEntry(
                at: $c->classified_at ?? $c->created_at,
                kind: 'system',
                title: $c->isManual()
                    ? 'Classification changed to '.$c->intent?->label().' by '.($c->overrider?->name ?? 'a team member')
                    : 'AI classified: '.$c->intent?->label().($c->confidence !== null ? ' — '.round($c->confidence * 100).'%' : ''),
                body: $c->isManual() ? trim('Was '.$c->previous_intent?->label().'. '.($c->override_reason ?? '')) : $c->summary,
                conversationId: $c->conversation_id,
            ));
        }

        $runs = $scope(AutomationRun::query(), 'automation_runs')
            ->whereIn('conversation_id', $conversationIds)
            ->with(['automation:id,name', 'actionRuns:id,automation_run_id,action_type,status,result'])
            ->latest('id')->limit($limit)->get();

        foreach ($runs as $run) {
            $entries->push(new TimelineEntry(
                at: $run->created_at,
                kind: 'automation',
                title: 'Automation: '.($run->automation?->name ?? 'Deleted automation'),
                body: $run->actionRuns->isEmpty() ? $run->failure_reason : null,
                details: $run->actionRuns->map(fn ($a) => [
                    'ok' => $a->status->value === 'completed',
                    'status' => $a->status->label(),
                    'text' => $a->result['message'] ?? $a->action_type->label(),
                ])->all(),
                conversationId: $run->conversation_id,
            ));
        }

        $followUps = FollowUp::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->when($conversationId, fn ($q) => $q->where('conversation_id', $conversationId))
            ->latest('updated_at')->limit($limit)->get();

        foreach ($followUps as $f) {
            $what = $f->isAutomated() ? 'Automated follow-up' : 'Follow-up';
            $entries->push(new TimelineEntry(at: $f->created_at, kind: $f->isAutomated() ? 'automation' : 'business', title: "{$what} scheduled", body: $f->reason(), conversationId: $f->conversation_id));

            $closedAt = match ($f->status) {
                FollowUpStatus::Completed => $f->completed_at,
                FollowUpStatus::Cancelled => $f->cancelled_at,
                FollowUpStatus::Skipped, FollowUpStatus::Failed => $f->processed_at ?? $f->updated_at,
                default => null,
            };

            if ($closedAt !== null) {
                $entries->push(new TimelineEntry(
                    at: $closedAt,
                    kind: $f->status === FollowUpStatus::Completed ? 'business' : 'system',
                    title: "{$what} ".strtolower($f->status->label()),
                    body: $f->skip_reason?->label() ?? $f->cancelled_reason?->label() ?? $f->outcome,
                    conversationId: $f->conversation_id,
                ));
            }
        }

        $tasks = Task::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->when($conversationId, fn ($q) => $q->where('conversation_id', $conversationId))
            ->latest('id')->limit($limit)->get();

        foreach ($tasks as $t) {
            $entries->push(new TimelineEntry(at: $t->created_at, kind: $t->idempotency_key ? 'automation' : 'business', title: 'Task created', body: $t->title, conversationId: $t->conversation_id));

            if ($t->completed_at !== null) {
                $entries->push(new TimelineEntry(at: $t->completed_at, kind: 'business', title: 'Task completed', body: $t->title, conversationId: $t->conversation_id));
            }
        }

        $events = ConversationEvent::query()
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->when($conversationId, fn ($q) => $q->where('conversation_id', $conversationId))
            ->with('user:id,name')
            ->latest('id')->limit($limit)->get();

        foreach ($events as $e) {
            $entries->push(new TimelineEntry(at: $e->created_at, kind: $e->user_id ? 'business' : 'system', title: $this->eventTitle($e), body: $e->data['note'] ?? ($e->data['reason'] ?? null), conversationId: $e->conversation_id));
        }

        if ($conversationId === null) {
            $entries->push(new TimelineEntry(at: $customer->created_at, kind: 'system', title: 'Customer created'));
        }

        return $entries->sortByDesc(fn (TimelineEntry $e) => $e->at->getTimestamp())->take($limit)->values();
    }

    private function eventTitle(ConversationEvent $event): string
    {
        $by = $event->user?->name;

        return match ($event->type) {
            'closed' => 'Conversation closed'.($by ? " by {$by}" : '').(isset($event->data['reason']) ? ' ('.str_replace('_', ' ', $event->data['reason']).')' : ''),
            'reopened' => ($event->data['by'] ?? null) === 'message' ? 'Conversation reopened by a customer reply' : 'Conversation reopened'.($by ? " by {$by}" : ''),
            'status_changed' => 'Status changed to '.str_replace('_', ' ', $event->data['to'] ?? '').($by ? " by {$by}" : ''),
            'classification_changed' => 'Classification corrected'.($by ? " by {$by}" : ''),
            default => Str::headline($event->type),
        };
    }

    private function excerpt(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $line = collect(explode("\n", $text))->map(fn ($l) => trim($l))->first(fn ($l) => $l !== '' && ! str_starts_with($l, '>'));

        return $line === null ? null : Str::limit($line, 160);
    }
}
