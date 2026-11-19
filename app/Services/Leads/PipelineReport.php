<?php

namespace App\Services\Leads;

use App\Enums\LeadStage;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Read-only views of the sales pipeline: one column per lead stage, and the business-wide report.
 *
 * Counts and values are computed in SQL over every matching lead; only the cards in each column are limited.
 */
class PipelineReport
{
    /** Most recent leads shown per column. The column's count and value still cover every lead in that stage. */
    public const COLUMN_LIMIT = 50;

    /**
     * @return array<string, array{stage: LeadStage, count: int, value: int, customers: Collection<int, Customer>}>
     */
    public function board(Organization $organization, ?string $tag = null): array
    {
        $totals = $this->totalsByStage($organization, $tag);

        $board = [];

        foreach (LeadStage::cases() as $stage) {
            $board[$stage->value] = [
                'stage' => $stage,
                'count' => (int) ($totals[$stage->value]->total_count ?? 0),
                'value' => (int) ($totals[$stage->value]->total_value ?? 0),
                'customers' => $this->columnCustomers($organization, $stage, $tag),
            ];
        }

        return $board;
    }

    /**
     * The numbers that matter to an owner: what is open, what was won this month, and the win rate.
     *
     * @return array{open_count: int, open_value: int, won_count: int, won_value: int, win_rate: ?int}
     */
    public function summary(Organization $organization): array
    {
        $totals = $this->totalsByStage($organization);

        $openCount = 0;
        $openValue = 0;

        foreach (LeadStage::cases() as $stage) {
            if ($stage->isClosed()) {
                continue;
            }

            $openCount += (int) ($totals[$stage->value]->total_count ?? 0);
            $openValue += (int) ($totals[$stage->value]->total_value ?? 0);
        }

        $monthStart = $organization->localNow()->startOfMonth()->utc();

        $wonThisMonth = $this->scoped($organization)
            ->toBase()
            ->where('customers.lead_stage', LeadStage::Won->value)
            ->where('customers.lead_stage_changed_at', '>=', $monthStart)
            ->selectRaw('count(*) as total_count, coalesce(sum(deal_value_cents), 0) as total_value')
            ->first();

        $won = (int) ($totals[LeadStage::Won->value]->total_count ?? 0);
        $lost = (int) ($totals[LeadStage::Lost->value]->total_count ?? 0);

        return [
            'open_count' => $openCount,
            'open_value' => $openValue,
            'won_count' => (int) $wonThisMonth->total_count,
            'won_value' => (int) $wonThisMonth->total_value,
            'win_rate' => $won + $lost === 0 ? null : (int) round($won * 100 / ($won + $lost)),
        ];
    }

    /**
     * Count and value per stage, keyed by the stored stage value.
     *
     * @return SupportCollection<string, object{total_count: int, total_value: int}>
     */
    private function totalsByStage(Organization $organization, ?string $tag = null): SupportCollection
    {
        return $this->scoped($organization, $tag)
            ->toBase()
            ->selectRaw('customers.lead_stage, count(*) as total_count, coalesce(sum(customers.deal_value_cents), 0) as total_value')
            ->groupBy('customers.lead_stage')
            ->get()
            ->keyBy('lead_stage');
    }

    /**
     * @return Collection<int, Customer>
     */
    private function columnCustomers(Organization $organization, LeadStage $stage, ?string $tag): Collection
    {
        return $this->scoped($organization, $tag)
            ->where('customers.lead_stage', $stage->value)
            ->with('tags:id,name,slug')
            ->orderByDesc('customers.lead_stage_changed_at')
            ->limit(self::COLUMN_LIMIT)
            ->get(['customers.id', 'customers.organization_id', 'customers.name', 'customers.company', 'customers.lead_stage', 'customers.deal_value_cents']);
    }

    /**
     * @return Builder<Customer>
     */
    private function scoped(Organization $organization, ?string $tag = null): Builder
    {
        return Customer::query()
            ->where('customers.organization_id', $organization->id)
            ->when($tag !== null && $tag !== '', fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('customer_tags.slug', $tag)));
    }
}
