<?php

namespace App\Livewire\Estimates;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Services\Estimates\EstimateDirectory;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Estimate list. Searching, filtering and paging happen in EstimateDirectory (SQL).
 */
#[Layout('components.layouts.app')]
#[Title('Estimates')]
class EstimateIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $customer = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $min = '';

    #[Url(except: '')]
    public string $max = '';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    public function mount(): void
    {
        $this->authorize('viewAny', Estimate::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'customer', 'from', 'to', 'min', 'max', 'perPage');
        $this->resetPage();
    }

    /**
     * Counts by status and the total value of every estimate in the business (not only the filtered list).
     *
     * @return array{total: int, accepted: int, sent: int, draft: int, value: string}
     */
    #[Computed]
    public function summary(): array
    {
        $organization = Auth::user()->organization;
        $byStatus = $organization->estimates()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'accepted' => (int) ($byStatus[EstimateStatus::Accepted->value] ?? 0),
            'sent' => (int) ($byStatus[EstimateStatus::Sent->value] ?? 0),
            'draft' => (int) ($byStatus[EstimateStatus::Draft->value] ?? 0),
            'value' => Money::formatDecimal((string) $organization->estimates()->sum('total'), $organization->currencyCode()),
        ];
    }

    public function render(EstimateDirectory $directory)
    {
        $organization = Auth::user()->organization;

        return view('livewire.estimates.estimate-index', [
            'organization' => $organization,
            'estimates' => $directory->paginate($organization, [
                'search' => $this->search,
                'status' => $this->status,
                'customer' => $this->customer,
                'from' => $this->from,
                'to' => $this->to,
                'min' => $this->min,
                'max' => $this->max,
                'per_page' => $this->perPage,
            ]),
            'hasAnyEstimate' => $organization->estimates()->exists(),
            'filteredCustomer' => ctype_digit($this->customer) ? $organization->customers()->whereKey((int) $this->customer)->value('name') : null,
            'filtering' => ($this->search.$this->status.$this->customer.$this->from.$this->to.$this->min.$this->max) !== '',
            'statuses' => EstimateStatus::cases(),
        ]);
    }
}
