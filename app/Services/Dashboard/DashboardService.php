<?php

namespace App\Services\Dashboard;

use App\Enums\ConversationStatus;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\TaskStatus;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Answers "What needs my attention today?" for one organization.
 *
 * Every query is filtered by the organization passed in (always the signed-in user's), uses
 * COUNT/FILTER aggregates or ORDER BY … LIMIT, and eager-loads relations, so the number of
 * queries is fixed regardless of how many customers, messages or follow-ups exist.
 * "Today" is the organization's day, in its timezone.
 */
class DashboardService
{
    public function __construct(private readonly AttentionPriorityRules $priorities) {}

    /**
     * Everything the dashboard shows.
     */
    public function snapshot(User $user): DashboardSnapshot
    {
        $organization = $user->organization ?? abort(403);
        [$start, $end] = $this->today($organization);

        $followUpCounts = $this->followUpCounts($organization, $start, $end);
        $messageCounts = $this->messageCounts($organization, $start, $end);
        $customerCounts = $this->customerCounts($organization, $start, $end);
        $statusCounts = $this->conversationStatusCounts($organization);
        $overdue = $this->overdueFollowUps($organization, $start);
        $today = $this->todaysFollowUps($organization, $start, $end);
        $tasks = $this->openTasks($organization, $end);

        return new DashboardSnapshot(
            localNow: $organization->localNow(),
            summary: [
                'overdue' => $followUpCounts['overdue'],
                'due_today' => $followUpCounts['due_today'],
                'waiting' => $statusCounts['waiting'],
                'new_replies' => $messageCounts['new_replies'],
            ],
            today: [
                'new_customers' => $customerCounts['new_today'],
                'customer_replies' => $messageCounts['replies_today'],
                'follow_ups_completed' => $followUpCounts['completed_today'],
                'follow_ups_due' => $followUpCounts['due_today'],
                'emails_sent' => $messageCounts['emails_sent_today'],
            ],
            conversationStatuses: $statusCounts['by_status'],
            hasCustomers: $customerCounts['total'] > 0,
            hasOpenFollowUps: $followUpCounts['open'] > 0,
            attention: $this->attentionItems($organization, $overdue, $today, $tasks),
            overdueFollowUps: $overdue,
            todaysFollowUps: $today,
            tasks: $tasks,
            taskCounts: $this->taskCounts($organization, $end),
            estimates: $this->estimateCounts($organization, $start, $end),
            recentReplies: $this->recentReplies($organization),
            automationRuns: $this->automationActivity($organization),
            automationFailures: $this->automationFailures($organization),
            notifications: $user->unreadNotifications()->limit((int) config('dashboard.limits.notifications'))->get(['id', 'data', 'created_at']),
            unreadNotifications: $user->unreadNotifications()->count(),
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} Start and end of the organization's today, in UTC.
     */
    public function today(Organization $organization): array
    {
        $now = $organization->localNow();

        return [$now->startOfDay()->utc(), $now->endOfDay()->utc()];
    }

    /**
     * Overdue = open and due before today (the same rule as the Follow-Ups page, so the numbers match).
     *
     * @return array{overdue: int, due_today: int, completed_today: int, open: int}
     */
    public function followUpCounts(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $open = "status in ('pending', 'due')";

        $row = FollowUp::query()
            ->forOrganization($organization)
            ->where(fn ($q) => $q->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due])->orWhere('completed_at', '>=', $start))
            ->selectRaw("count(*) filter (where {$open} and due_at < ?) as overdue", [$start])
            ->selectRaw("count(*) filter (where {$open} and due_at between ? and ?) as due_today", [$start, $end])
            ->selectRaw("count(*) filter (where status = 'completed' and completed_at between ? and ?) as completed_today", [$start, $end])
            ->selectRaw("count(*) filter (where {$open}) as open")
            ->toBase()
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Customer replies (in a conversation) recently and today, and emails the business sent today.
     *
     * @return array{new_replies: int, replies_today: int, emails_sent_today: int}
     */
    public function messageCounts(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $window = now()->subHours((int) config('dashboard.new_replies_hours'));
        $since = $window->lt($start) ? $window : $start;

        $row = Message::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('conversation_id')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('direction', MessageDirection::Inbound)->where('received_at', '>=', $since))
                ->orWhere(fn ($q) => $q->where('direction', MessageDirection::Outbound)->where('sent_at', '>=', $start)))
            ->selectRaw("count(*) filter (where direction = 'inbound' and received_at >= ?) as new_replies", [$window])
            ->selectRaw("count(*) filter (where direction = 'inbound' and received_at between ? and ?) as replies_today", [$start, $end])
            ->selectRaw("count(*) filter (where direction = 'outbound' and sent_at between ? and ?) as emails_sent_today", [$start, $end])
            ->toBase()
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * @return array{total: int, new_today: int}
     */
    public function customerCounts(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::table('customers')
            ->where('organization_id', $organization->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where created_at between ? and ?) as new_today', [$start, $end])
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Conversations per status, and how many are waiting for the business (one grouped query).
     *
     * @return array{by_status: array<string, int>, waiting: int}
     */
    public function conversationStatusCounts(Organization $organization): array
    {
        [$sql, $bindings] = Conversation::waitingForBusinessSql();

        $rows = Conversation::query()
            ->where('organization_id', $organization->id)
            ->groupBy('status')
            ->select('status')
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where {$sql}) as waiting", $bindings)
            ->toBase()
            ->get();

        $byStatus = array_fill_keys(array_column(ConversationStatus::cases(), 'value'), 0);
        $waiting = 0;

        foreach ($rows as $row) {
            $byStatus[$row->status] = (int) $row->total;
            $waiting += (int) $row->waiting;
        }

        return ['by_status' => $byStatus, 'waiting' => $waiting];
    }

    /**
     * Estimates sent and accepted today (the organization's day), and how many await a decision.
     *
     * @return array{sent_today: int, awaiting: int, accepted_today: int}
     */
    public function estimateCounts(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = Estimate::query()
            ->where('organization_id', $organization->id)
            ->where(fn ($q) => $q->whereIn('status', ['sent', 'viewed'])->orWhere('sent_at', '>=', $start)->orWhere('accepted_at', '>=', $start))
            ->selectRaw('count(*) filter (where sent_at between ? and ?) as sent_today', [$start, $end])
            ->selectRaw("count(*) filter (where status in ('sent', 'viewed')) as awaiting")
            ->selectRaw('count(*) filter (where accepted_at between ? and ?) as accepted_today', [$start, $end])
            ->toBase()
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Open tasks: due today, overdue, or undated ("to do"); later ones only count.
     *
     * @return array{open: int, due: int, overdue: int, later: int}
     */
    public function taskCounts(Organization $organization, CarbonImmutable $end): array
    {
        $row = Task::query()
            ->where('organization_id', $organization->id)
            ->where('status', TaskStatus::Pending)
            ->selectRaw('count(*) as open')
            ->selectRaw('count(*) filter (where due_at is null or due_at <= ?) as due', [$end])
            ->selectRaw('count(*) filter (where due_at < ?) as overdue', [now()])
            ->selectRaw('count(*) filter (where due_at > ?) as later', [$end])
            ->toBase()
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Open tasks to do today: overdue first, then by due time; undated tasks last.
     *
     * @return Collection<int, Task>
     */
    public function openTasks(Organization $organization, CarbonImmutable $end): Collection
    {
        return Task::query()
            ->where('organization_id', $organization->id)
            ->where('status', TaskStatus::Pending)
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', $end))
            ->with('customer:id,name')
            ->orderByRaw('due_at asc nulls last')
            ->orderBy('id')
            ->limit((int) config('dashboard.limits.tasks'))
            ->get(['id', 'organization_id', 'customer_id', 'conversation_id', 'title', 'priority', 'status', 'due_at', 'idempotency_key', 'created_at']);
    }

    /**
     * Conversations waiting for the business, overdue and due follow-ups, and open tasks, most urgent first.
     *
     * @param  Collection<int, FollowUp>  $overdue
     * @param  Collection<int, FollowUp>  $today
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, AttentionItem>
     */
    public function attentionItems(Organization $organization, Collection $overdue, Collection $today, Collection $tasks): Collection
    {
        $limit = (int) config('dashboard.limits.attention');

        $conversations = Conversation::query()
            ->where('organization_id', $organization->id)
            ->waitingForBusiness()
            ->with([
                'customer:id,name',
                'latestInboundMessage' => fn ($q) => $q->select('messages.id', 'messages.conversation_id', 'messages.received_at', 'messages.created_at')
                    ->selectRaw('left(messages.body_text, 300) as excerpt'),
                'latestClassification:message_classifications.id,message_classifications.conversation_id,intent,confidence,urgency,summary',
            ])
            ->orderByDesc('last_message_at')
            ->limit($limit * 2)
            ->get(['id', 'customer_id', 'status', 'latest_intent', 'needs_attention', 'last_message_at']);

        $items = $conversations->map(function (Conversation $conversation) {
            $classification = $conversation->latestClassification;
            $message = $conversation->latestInboundMessage;

            return new AttentionItem(
                kind: 'conversation',
                priority: $this->priorities->forConversation(
                    $conversation->status,
                    $conversation->latest_intent,
                    $classification?->confidence,
                    $classification?->urgency,
                    $conversation->needs_attention,
                ),
                customerId: $conversation->customer_id,
                customerName: $conversation->customer?->name ?? 'Customer',
                reason: $conversation->latest_intent?->label()
                    ?? ($conversation->status === ConversationStatus::WaitingBusiness ? 'Waiting for your reply' : 'Customer replied'),
                excerpt: $this->excerpt($message?->excerpt),
                intent: $conversation->latest_intent,
                confidence: $classification?->confidence,
                at: $message?->received_at ?? $message?->created_at ?? $conversation->last_message_at,
                url: route('inbox.show', $conversation->id),
                actionLabel: 'Open Conversation',
            );
        });

        $followUpItem = fn (FollowUp $followUp, bool $isOverdue) => new AttentionItem(
            kind: 'follow_up',
            priority: $this->priorities->forFollowUp($isOverdue),
            customerId: $followUp->customer_id,
            customerName: $followUp->customer?->name ?? 'Customer',
            reason: $isOverdue ? 'Follow-up overdue' : 'Follow-up due',
            excerpt: $followUp->reason(),
            intent: null,
            confidence: null,
            at: $followUp->due_at,
            url: route('follow-ups.index', ['filter' => $isOverdue ? 'overdue' : 'today']).'#follow-up-'.$followUp->id,
            actionLabel: 'View Follow-Up',
            overdue: $isOverdue,
        );

        // A task for a conversation that is already listed would only repeat it.
        $listed = $conversations->pluck('id')->all();

        $taskItems = $tasks
            ->filter(fn (Task $t) => $t->customer_id !== null && ! in_array($t->conversation_id, $listed, true))
            ->map(function (Task $task) {
                $isOverdue = $task->due_at !== null && $task->due_at->lt(now());

                return new AttentionItem(
                    kind: 'task',
                    priority: $this->priorities->forTask($task->priority, $isOverdue),
                    customerId: $task->customer_id,
                    customerName: $task->customer?->name ?? 'Customer',
                    reason: ($isOverdue ? 'Overdue task: ' : 'Task: ').Str::limit($task->title, 120),
                    excerpt: null,
                    intent: null,
                    confidence: null,
                    at: $task->due_at ?? $task->created_at,
                    url: $task->conversation_id
                        ? route('inbox.show', $task->conversation_id).'#tasks-heading'
                        : route('customers.show', $task->customer_id).'#tasks-heading',
                    actionLabel: 'Open Task',
                    overdue: $isOverdue,
                );
            });

        $items = $items
            ->concat($overdue->map(fn (FollowUp $f) => $followUpItem($f, true)))
            // Today's follow-ups whose time has come; later ones stay in "Today's follow-ups".
            ->concat($today->filter(fn (FollowUp $f) => $f->due_at->lte(now()))->map(fn (FollowUp $f) => $followUpItem($f, false)))
            ->concat($taskItems);

        return $items
            // Most urgent first, then most recent (an overdue task before one not yet due); name and kind keep equal items in a stable order.
            ->sortBy([
                fn (AttentionItem $a, AttentionItem $b) => $a->priority->rank() <=> $b->priority->rank(),
                fn (AttentionItem $a, AttentionItem $b) => $a->kind === 'task' && $b->kind === 'task' ? $b->overdue <=> $a->overdue : 0,
                fn (AttentionItem $a, AttentionItem $b) => $b->at <=> $a->at,
                fn (AttentionItem $a, AttentionItem $b) => [$a->customerName, $a->kind] <=> [$b->customerName, $b->kind],
            ])
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, FollowUp>
     */
    public function overdueFollowUps(Organization $organization, CarbonImmutable $start): Collection
    {
        return $this->openFollowUps($organization)
            ->where('due_at', '<', $start)
            ->orderBy('due_at')
            ->limit((int) config('dashboard.limits.follow_ups'))
            ->get();
    }

    /**
     * @return Collection<int, FollowUp>
     */
    public function todaysFollowUps(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->openFollowUps($organization)
            ->whereBetween('due_at', [$start, $end])
            ->orderBy('due_at')
            ->limit((int) config('dashboard.limits.follow_ups'))
            ->get();
    }

    /**
     * Latest customer replies with their AI classification.
     *
     * @return Collection<int, Message>
     */
    public function recentReplies(Organization $organization): Collection
    {
        return Message::query()
            ->where('organization_id', $organization->id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNotNull('conversation_id')
            ->whereNotNull('received_at')
            ->with([
                'conversation:id,customer_id',
                'conversation.customer:id,name',
                'latestClassification:message_classifications.id,message_classifications.message_id,intent,confidence',
            ])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit((int) config('dashboard.limits.replies'))
            ->select('id', 'organization_id', 'conversation_id', 'received_at')
            ->selectRaw('left(body_text, 300) as excerpt')
            ->get();
    }

    /**
     * Failed automation executions in the last 7 days.
     */
    public function automationFailures(Organization $organization): int
    {
        return AutomationRun::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
    }

    /**
     * @return Collection<int, AutomationRun>
     */
    public function automationActivity(Organization $organization): Collection
    {
        return AutomationRun::query()
            ->where('organization_id', $organization->id)
            ->with([
                'automation:id,name',
                'customer:id,name',
                'actionRuns:id,automation_run_id,action_type,status,result',
            ])
            ->latest()
            ->latest('id')
            ->limit((int) config('dashboard.limits.automation_runs'))
            ->get(['id', 'organization_id', 'automation_id', 'conversation_id', 'customer_id', 'status', 'failure_reason', 'resume_at', 'created_at']);
    }

    /**
     * @return Builder<FollowUp>
     */
    private function openFollowUps(Organization $organization): Builder
    {
        return FollowUp::query()
            ->forOrganization($organization)
            ->open()
            ->with('customer:id,name')
            ->select(['id', 'organization_id', 'customer_id', 'conversation_id', 'type', 'status', 'due_at', 'notes', 'metadata', 'due_notified_at', 'subject', 'body']);
    }

    private function excerpt(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $line = trim(Str::of($text)->explode("\n")->map(fn ($l) => trim($l))->first(fn ($l) => $l !== '' && ! str_starts_with($l, '>')) ?? '');

        return $line === '' ? null : Str::limit($line, 120);
    }
}
