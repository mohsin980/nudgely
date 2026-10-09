<?php

namespace App\Services\Estimates;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Models\Organization;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The estimate list: search, filters and paging, all in SQL for one organization.
 */
class EstimateDirectory
{
    public const PER_PAGE = [25, 50, 100];

    /**
     * @param  array<string, mixed>  $filters  search, status (or "awaiting" = sent or viewed), customer, from, to (Y-m-d, created date), min, max (total), per_page
     */
    public function paginate(Organization $organization, array $filters): LengthAwarePaginator
    {
        $query = Estimate::query()
            ->forOrganization($organization)
            ->with('customer:id,name,email')
            ->select(['id', 'organization_id', 'customer_id', 'estimate_number', 'revision', 'status', 'title', 'total', 'currency', 'valid_until', 'sent_at', 'created_at']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(estimates.estimate_number) like ?', [$like])
                ->orWhereRaw('lower(estimates.title) like ?', [$like])
                // Customer name and email, through the customers' indexed search column.
                ->orWhereIn('estimates.customer_id', fn ($c) => $c->select('id')->from('customers')
                    ->where('organization_id', $organization->id)
                    ->whereRaw('search_text like ?', [$like])));
        }

        if (($filters['status'] ?? '') === 'awaiting') {
            $query->whereIn('estimates.status', EstimateStatus::awaitingCustomer());
        } elseif (($status = EstimateStatus::tryFrom((string) ($filters['status'] ?? ''))) !== null) {
            $query->where('estimates.status', $status);
        }

        if (ctype_digit((string) ($filters['customer'] ?? ''))) {
            $query->where('estimates.customer_id', (int) $filters['customer']);
        }

        [$from, $to] = [$this->date($filters['from'] ?? null), $this->date($filters['to'] ?? null)];
        $timezone = $organization->timezone();

        if ($from !== null) {
            $query->where('estimates.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }

        if ($to !== null) {
            $query->where('estimates.created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }

        if (($min = Money::tryParse($filters['min'] ?? null)) !== null) {
            $query->where('estimates.total', '>=', Money::toDecimal($min));
        }

        if (($max = Money::tryParse($filters['max'] ?? null)) !== null) {
            $query->where('estimates.total', '<=', Money::toDecimal($max));
        }

        $perPage = in_array((int) ($filters['per_page'] ?? 25), self::PER_PAGE, true) ? (int) $filters['per_page'] : 25;

        return $query->latest('estimates.created_at')->latest('estimates.id')->paginate($perPage);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
