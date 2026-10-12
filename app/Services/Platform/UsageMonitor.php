<?php

namespace App\Services\Platform;

use App\Enums\Billing\LimitKey;
use App\Enums\Billing\QuotaState;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Platform-wide view of plan usage. Every number comes from UsageService (the same definitions the backend enforces
 * with: periods, counted rows, demo customers excluded), loaded for many organizations at once. Nothing is estimated.
 *
 * Current-period usage (what limits count) and lifetime totals are kept apart. History that is not recorded
 * (how many customers an organization had last month) is reported as unavailable, never reconstructed.
 */
class UsageMonitor
{
    public function __construct(private readonly UsageService $usage, private readonly EntitlementService $entitlements) {}

    /**
     * One row per organization: plan, billing period and, for every limit, usage, limit, remaining and state.
     *
     * @param  Collection<int, Organization>  $organizations
     * @return array<int, array<string, mixed>> keyed by organization ID
     */
    public function rows(Collection $organizations): array
    {
        if ($organizations->isEmpty()) {
            return [];
        }

        $subscriptions = $this->usage->currentSubscriptions($organizations->pluck('id')->all());
        $usage = $this->usage->usageForMany($organizations, $subscriptions);
        $warn = (int) config('billing.usage_warning_percent', 80);
        $rows = [];

        foreach ($organizations as $organization) {
            $subscription = $subscriptions[$organization->id] ?? null;
            $plan = $this->entitlements->planFor($organization, $subscription);
            [$start, $end] = $this->usage->periodFor($organization, $subscription);
            $limits = [];
            $worst = QuotaState::Unlimited;
            $ratio = 0.0;

            foreach (LimitKey::cases() as $key) {
                $used = $usage[$organization->id][$key->value];
                $limit = $plan->limit($key);
                $state = QuotaState::for($used, $limit, $warn);

                $limits[$key->value] = [
                    'used' => $used,
                    'limit' => $limit,
                    'remaining' => $limit === null ? null : max(0, $limit - $used),
                    'percent' => $limit === null ? null : ($limit === 0 ? ($used > 0 ? 100 : 0) : (int) round($used * 100 / $limit)),
                    'state' => $state,
                ];

                if ($state->severity() > $worst->severity()) {
                    $worst = $state;
                }

                $ratio = max($ratio, $limit === null ? 0.0 : ($limit === 0 ? ($used > 0 ? 2.0 : 1.0) : $used / $limit));
            }

            $rows[$organization->id] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'plan_key' => $plan->key,
                'plan' => $plan->name,
                'subscription_status' => $subscription?->status->label(),
                'period_start' => $start,
                'period_end' => $end,
                'timezone' => $organization->timezone(),
                'limits' => $limits,
                'state' => $worst,
                'ratio' => $ratio,
            ];
        }

        return $rows;
    }

    /**
     * A page of organizations with their usage.
     *
     * Searching (name or exact ID) and ordering by name/ID/created use SQL and only the displayed page is measured.
     * Filtering by plan or quota state depends on computed usage (periods differ per organization), so those
     * filters measure organizations in batches of 200 and list the matches, worst first.
     *
     * @param  array{plan?: ?string, state?: ?string}  $filters
     */
    public function list(array $filters, ?string $search, string $sort, string $direction, int $page, int $perPage): LengthAwarePaginator
    {
        $base = Organization::query()
            ->when(filled($search), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $search)).'%')
                ->when(ctype_digit(trim((string) $search)), fn ($w) => $w->orWhere('id', (int) trim((string) $search)))));

        $plan = $filters['plan'] ?? null;
        $state = $filters['state'] ?? null;
        $options = ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'];

        if (blank($plan) && blank($state)) {
            $column = in_array($sort, ['name', 'id', 'created_at'], true) ? $sort : 'name';
            $total = (clone $base)->count();
            $organizations = $base->orderBy($column, $direction === 'desc' ? 'desc' : 'asc')->orderBy('id')->forPage($page, $perPage)->get();

            return new LengthAwarePaginator($this->rows($organizations), $total, $perPage, $page, $options);
        }

        $matches = [];

        $base->orderBy('id')->chunkById(200, function ($organizations) use (&$matches, $plan, $state) {
            foreach ($this->rows($organizations) as $row) {
                if (filled($plan) && $row['plan_key'] !== $plan) {
                    continue;
                }

                if (filled($state) && ! $this->matchesState($row['state'], $state)) {
                    continue;
                }

                $matches[$row['id']] = $row; // keyed by organization ID: the table's record key
            }
        });

        uasort($matches, fn ($a, $b) => [$b['ratio'], $a['name']] <=> [$a['ratio'], $b['name']]);

        return new LengthAwarePaginator(array_slice($matches, ($page - 1) * $perPage, $perPage, preserve_keys: true), count($matches), $perPage, $page, $options);
    }

    private function matchesState(QuotaState $worst, string $filter): bool
    {
        return match ($filter) {
            'attention' => $worst->severity() >= QuotaState::Near->severity(),
            'near' => $worst === QuotaState::Near,
            'at_limit' => $worst === QuotaState::AtLimit,
            'over' => $worst === QuotaState::Over,
            'ok' => $worst->severity() <= QuotaState::Ok->severity(),
            default => true,
        };
    }

    /**
     * Activity of one organization in the current plan period, the last 30 days and over its lifetime. Only measures
     * that are stored with a timestamp can be windowed; others are current-only.
     *
     * @return array<string, array<string, int|null>>
     */
    public function activity(Organization $organization): array
    {
        $subscription = $this->usage->currentSubscriptions([$organization->id])[$organization->id] ?? null;
        [$start, $end] = $this->usage->periodFor($organization, $subscription);
        $sentStatuses = array_map(fn (MessageStatus $s) => $s->value, array_filter(MessageStatus::cases(), fn (MessageStatus $s) => $s->countsAsSent()));
        $last30 = now()->subDays(30);

        $count = fn ($query, string $column) => [
            'period' => (clone $query)->where($column, '>=', $start)->where($column, '<', $end)->count(),
            'last_30_days' => (clone $query)->where($column, '>=', $last30)->count(),
            'lifetime' => (clone $query)->count(),
        ];

        return [
            'Outbound emails (counted against the plan)' => $count(Message::query()->where('organization_id', $organization->id)->where('direction', MessageDirection::Outbound)->whereIn('status', $sentStatuses), 'created_at'),
            'Failed sends (not counted)' => $count(Message::query()->where('organization_id', $organization->id)->where('direction', MessageDirection::Outbound)->where('status', MessageStatus::Failed), 'created_at'),
            'New estimates' => $count(Estimate::query()->where('organization_id', $organization->id)->where('revision', 1), 'created_at'),
            'Follow-ups completed' => $count(FollowUp::query()->where('organization_id', $organization->id)->where('status', FollowUpStatus::Completed)->whereNotNull('completed_at'), 'completed_at'),
        ];
    }
}
