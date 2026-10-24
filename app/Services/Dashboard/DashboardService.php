<?php

namespace App\Services\Dashboard;

use App\Enums\Billing\LimitKey;
use App\Enums\ConversationStatus;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\TaskStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Support\Money;
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
    public function __construct(
        private readonly AttentionPriorityRules $priorities,
        private readonly EntitlementService $entitlements,
    ) {}

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
        $pipeline = $this->estimatePipeline($organization, $end->subDays(30));
        $health = $this->automationHealth($organization);
        $upcoming = $this->upcomingFollowUps($organization, $end);

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
            estimatePipeline: $pipeline,
            automationHealth: $health,
            alerts: $this->alerts($user, $organization, $followUpCounts['overdue'], $statusCounts['waiting'], $health, $this->expiringEstimateCount($organization), $this->failedEmailCount($organization)),
            upcomingFollowUps: $upcoming['items'],
            upcomingCount: $upcoming['count'],
            recentEstimates: $this->recentEstimates($organization),
            planUsage: $this->planUsage($organization),
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
            ->selectRaw("count(case when {$open} and due_at < ? then 1 end) as overdue", [$start])
            ->selectRaw("count(case when {$open} and due_at between ? and ? then 1 end) as due_today", [$start, $end])
            ->selectRaw("count(case when status = 'completed' and completed_at between ? and ? then 1 end) as completed_today", [$start, $end])
            ->selectRaw("count(case when {$open} then 1 end) as open")
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
            ->selectRaw("count(case when direction = 'inbound' and received_at >= ? then 1 end) as new_replies", [$window])
            ->selectRaw("count(case when direction = 'inbound' and received_at between ? and ? then 1 end) as replies_today", [$start, $end])
            ->selectRaw("count(case when direction = 'outbound' and sent_at between ? and ? then 1 end) as emails_sent_today", [$start, $end])
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
            ->selectRaw('count(case when created_at between ? and ? then 1 end) as new_today', [$start, $end])
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
            ->selectRaw("count(case when {$sql} then 1 end) as waiting", $bindings)
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
            ->selectRaw('count(case when sent_at between ? and ? then 1 end) as sent_today', [$start, $end])
            ->selectRaw("count(case when status in ('sent', 'viewed') then 1 end) as awaiting")
            ->selectRaw('count(case when accepted_at between ? and ? then 1 end) as accepted_today', [$start, $end])
            ->toBase()
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * The estimate pipeline. Cancelled estimates (superseded revisions) are left out.
     *
     * "Outstanding" is awaiting the customer (sent or viewed). Values are the estimates' totals: potential value,
     * not collected revenue. Accepted value counts estimates accepted since $acceptedSince.
     *
     * @return array{sent_7d: int, sent_prev_7d: int, sent_trend: ?int, draft: int, sent: int, viewed: int, accepted: int, declined: int, expired: int, outstanding_count: int, outstanding_cents: int, accepted_recent_count: int, accepted_recent_cents: int}
     */
    public function estimatePipeline(Organization $organization, CarbonImmutable $acceptedSince): array
    {
        $row = Estimate::query()
            ->where('organization_id', $organization->id)
            ->where('status', '!=', 'cancelled')
            ->selectRaw("count(case when status = 'draft' then 1 end) as draft")
            ->selectRaw("count(case when status = 'sent' then 1 end) as sent")
            ->selectRaw("count(case when status = 'viewed' then 1 end) as viewed")
            ->selectRaw("count(case when status = 'accepted' then 1 end) as accepted")
            ->selectRaw("count(case when status = 'declined' then 1 end) as declined")
            ->selectRaw("count(case when status = 'expired' then 1 end) as expired")
            ->selectRaw("count(case when status in ('sent', 'viewed') then 1 end) as outstanding_count")
            ->selectRaw("coalesce(sum(case when status in ('sent', 'viewed') then total end), 0) as outstanding_total")
            ->selectRaw('count(case when sent_at >= ? then 1 end) as sent_7d', [now()->subDays(7)])
            ->selectRaw('count(case when sent_at >= ? and sent_at < ? then 1 end) as sent_prev_7d', [now()->subDays(14), now()->subDays(7)])
            ->selectRaw("count(case when status = 'accepted' and accepted_at >= ? then 1 end) as accepted_recent_count", [$acceptedSince])
            ->selectRaw("coalesce(sum(case when status = 'accepted' and accepted_at >= ? then total end), 0) as accepted_recent_total", [$acceptedSince])
            ->toBase()
            ->first();

        return [
            'draft' => (int) $row->draft,
            'sent' => (int) $row->sent,
            'viewed' => (int) $row->viewed,
            'accepted' => (int) $row->accepted,
            'declined' => (int) $row->declined,
            'expired' => (int) $row->expired,
            'outstanding_count' => (int) $row->outstanding_count,
            'outstanding_cents' => Money::parse((string) $row->outstanding_total),
            'sent_7d' => (int) $row->sent_7d,
            'sent_prev_7d' => (int) $row->sent_prev_7d,
            'sent_trend' => (int) $row->sent_prev_7d > 0 ? (int) round(((int) $row->sent_7d - (int) $row->sent_prev_7d) / (int) $row->sent_prev_7d * 100) : null,
            'accepted_recent_count' => (int) $row->accepted_recent_count,
            'accepted_recent_cents' => Money::parse((string) $row->accepted_recent_total),
        ];
    }

    /**
     * Whether automated follow-ups are working, from execution results and sending readiness. An enabled automation
     * is not counted as healthy on its own.
     *
     * @return array{active: int, paused: int, scheduled_follow_ups: int, completed_7d: int, failed_7d: int, email_ready: bool, status: string}
     */
    public function automationHealth(Organization $organization): array
    {
        $automations = DB::table('automations')->where('organization_id', $organization->id)
            ->selectRaw("count(case when status = 'active' then 1 end) as active")
            ->selectRaw("count(case when status = 'paused' then 1 end) as paused")
            ->first();

        $runs = AutomationRun::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw("count(case when status = 'completed' then 1 end) as completed")
            ->selectRaw("count(case when status = 'failed' then 1 end) as failed")
            ->toBase()
            ->first();

        $emailReady = $organization->emailConnections()->default()->verified()
            ->where('connection_status', 'active')->exists();

        $active = (int) $automations->active;
        $completed = (int) $runs->completed;
        $failed = (int) $runs->failed;

        $status = match (true) {
            ! $emailReady => 'email_not_ready',
            $failed > 0 => 'failing',
            $active > 0 && $completed === 0 => 'no_recent_runs',
            $active > 0 => 'healthy',
            default => 'idle',
        };

        return [
            'active' => $active,
            'paused' => (int) $automations->paused,
            'scheduled_follow_ups' => $this->openFollowUps($organization)->count(),
            'completed_7d' => $completed,
            'failed_7d' => $failed,
            'email_ready' => $emailReady,
            'status' => $status,
        ];
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
            ->selectRaw('count(case when due_at is null or due_at <= ? then 1 end) as due', [$end])
            ->selectRaw('count(case when due_at < ? then 1 end) as overdue', [now()])
            ->selectRaw('count(case when due_at > ? then 1 end) as later', [$end])
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
            ->with(['customer:id,name', 'assignee:id,name'])
            ->orderByRaw('due_at is null, due_at asc')
            ->orderBy('id')
            ->limit((int) config('dashboard.limits.tasks'))
            ->get(['id', 'organization_id', 'customer_id', 'conversation_id', 'assigned_to', 'title', 'priority', 'status', 'due_at', 'idempotency_key', 'created_at']);
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
                'conversation:id,customer_id,status,needs_attention,latest_intent',
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
    /**
     * Open follow-ups after today and within the next seven days: the list and the total.
     *
     * @return array{items: Collection<int, FollowUp>, count: int}
     */
    public function upcomingFollowUps(Organization $organization, CarbonImmutable $endOfToday): array
    {
        $window = $this->openFollowUps($organization)
            ->where('due_at', '>', $endOfToday)
            ->where('due_at', '<=', $endOfToday->addDays(7));

        return [
            'count' => (clone $window)->count(),
            'items' => $window->orderBy('due_at')->limit((int) config('dashboard.limits.follow_ups'))->get(),
        ];
    }

    /**
     * Sent or viewed estimates whose "valid until" date falls in the next three days (the organization's calendar).
     */
    public function expiringEstimateCount(Organization $organization): int
    {
        $today = $organization->localNow();

        return Estimate::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['sent', 'viewed'])
            ->whereBetween('valid_until', [$today->toDateString(), $today->addDays(3)->toDateString()])
            ->count();
    }

    /**
     * Outbound emails that failed in the last seven days. Retries are one message, so none is counted twice.
     */
    public function failedEmailCount(Organization $organization): int
    {
        return Message::query()
            ->where('organization_id', $organization->id)
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
    }

    /**
     * The newest estimates, excluding superseded (cancelled) revisions.
     *
     * @return Collection<int, Estimate>
     */
    public function recentEstimates(Organization $organization): Collection
    {
        return Estimate::query()
            ->where('organization_id', $organization->id)
            ->where('status', '!=', 'cancelled')
            ->with('customer:id,name')
            ->latest('created_at')->latest('id')
            ->limit(5)
            ->get(['id', 'organization_id', 'customer_id', 'estimate_number', 'revision', 'status', 'title', 'total', 'currency', 'created_at']);
    }

    /**
     * The plan name and how much of each limit is used. The overall percentage is the fullest of the measures that
     * grow with use (customers, estimates, automations) and names which one it is. Team seats are listed but do not
     * drive it: one seat in use on a one-seat plan is the owner, not consumption. It is null when none of those
     * measures has a limit.
     *
     * @return array{plan: string, percent: ?int, fullest: ?string, rows: list<array{label: string, used: int, limit: ?int}>}
     */
    public function planUsage(Organization $organization): array
    {
        $summary = $this->entitlements->summary($organization);
        $growing = [LimitKey::Customers, LimitKey::Estimates, LimitKey::Automations];
        $rows = [];
        $fullest = null;
        $fullestLabel = null;

        foreach ([LimitKey::Customers, LimitKey::Estimates, LimitKey::TeamMembers, LimitKey::Automations] as $key) {
            $row = $summary[$key->value];
            $rows[] = ['label' => $row['label'], 'used' => (int) $row['used'], 'limit' => $row['limit'] === null ? null : (int) $row['limit']];

            if (in_array($key, $growing, true) && $row['limit'] !== null && $row['limit'] > 0) {
                $ratio = $row['used'] / $row['limit'];

                if ($fullest === null || $ratio > $fullest) {
                    $fullest = $ratio;
                    $fullestLabel = $row['label'];
                }
            }
        }

        return [
            'plan' => $this->entitlements->plan($organization)->name,
            'percent' => $fullest === null ? null : (int) min(100, round($fullest * 100)),
            'fullest' => $fullestLabel,
            'rows' => $rows,
        ];
    }

    /**
     * Grouped problems that need the owner. Each row has a count and, only when the user may use the destination, a
     * link. Customer replies and overdue follow-ups come first.
     *
     * @param  array<string, mixed>  $health
     * @return Collection<int, array{key: string, icon: string, tone: string, title: string, description: string, count: int, action: ?string, url: ?string, hint: ?string}>
     */
    public function alerts(User $user, Organization $organization, int $overdue, int $waiting, array $health, int $expiring, int $failedEmails): Collection
    {
        $rows = collect();

        if ($waiting > 0) {
            $rows->push(['key' => 'replies', 'icon' => 'chat', 'tone' => 'violet', 'title' => 'Customer replied: needs your response', 'count' => $waiting,
                'description' => trans_choice(':count customer has replied and is waiting for a response.|:count customers have replied and are waiting for a response.', $waiting),
                'action' => 'View messages', 'url' => route('inbox.index', ['filter' => 'waiting']), 'hint' => null]);
        }

        if ($overdue > 0) {
            $rows->push(['key' => 'overdue', 'icon' => 'clock', 'tone' => 'red', 'title' => 'Overdue follow-ups', 'count' => $overdue,
                'description' => trans_choice(':count follow-up is past due and still open.|:count follow-ups are past due and still open.', $overdue),
                'action' => 'View follow-ups', 'url' => route('follow-ups.index', ['filter' => 'overdue']), 'hint' => null]);
        }

        if ($failedEmails > 0) {
            $rows->push(['key' => 'failed_emails', 'icon' => 'exclamation', 'tone' => 'red', 'title' => 'Emails failed to send', 'count' => $failedEmails,
                'description' => trans_choice(':count email failed to send in the last 7 days.|:count emails failed to send in the last 7 days.', $failedEmails),
                'action' => 'View messages', 'url' => route('inbox.index'), 'hint' => null]);
        }

        if (! $health['email_ready']) {
            $rows->push(['key' => 'email', 'icon' => 'envelope', 'tone' => 'sky', 'title' => 'Email sending needs attention', 'count' => 1,
                'description' => 'No verified, connected sending email is set as the default, so follow-ups cannot be sent.',
                'action' => $user->can('manage-email') ? 'Fix now' : null, 'url' => $user->can('manage-email') ? route('settings.email') : null,
                'hint' => $user->can('manage-email') ? null : 'Ask the business owner to connect and verify a sending email.']);
        }

        if ($health['failed_7d'] > 0) {
            $canView = $user->can('viewAny', Automation::class);
            $rows->push(['key' => 'automation', 'icon' => 'bolt', 'tone' => 'red', 'title' => 'Automation needs attention', 'count' => $health['failed_7d'],
                'description' => trans_choice(':count automation execution failed in the last 7 days.|:count automation executions failed in the last 7 days.', $health['failed_7d']),
                'action' => $canView ? 'View automations' : null, 'url' => $canView ? route('automations.index') : null,
                'hint' => $canView ? null : 'Ask the business owner to review it.']);
        }

        if ($expiring > 0) {
            $rows->push(['key' => 'expiring', 'icon' => 'document', 'tone' => 'amber', 'title' => 'Estimates expiring soon', 'count' => $expiring,
                'description' => trans_choice(':count estimate expires within 3 days.|:count estimates expire within 3 days.', $expiring),
                'action' => 'View estimates', 'url' => route('estimates.index', ['status' => 'awaiting']), 'hint' => null]);
        }

        return $rows;
    }

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
