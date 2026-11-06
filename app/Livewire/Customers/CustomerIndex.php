<?php

namespace App\Livewire\Customers;

use App\Enums\ConversationStatus;
use App\Enums\CustomerStatus;
use App\Enums\FollowUpStatus;
use App\Models\Customer;
use App\Services\Customers\CustomerDirectory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer list. All filtering, searching, sorting and paging happens in CustomerDirectory (SQL).
 */
#[Layout('components.layouts.app')]
#[Title('Customers')]
class CustomerIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $conversation = '';

    #[Url(as: 'follow_up', except: '')]
    public string $followUp = '';

    #[Url(except: '')]
    public string $intent = '';

    #[Url(except: 'recent')]
    public string $sort = 'recent';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'conversation', 'followUp', 'intent', 'sort', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Totals for the whole business (not only the filtered list): active, inactive, and with or without an open follow-up.
     *
     * @return array{total: int, active: int, inactive: int, follow_up: int, no_follow_up: int}
     */
    #[Computed]
    public function summary(): array
    {
        $customers = Auth::user()->organization->customers();
        $byStatus = (clone $customers)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $byStatus->sum();
        $withFollowUp = (clone $customers)
            ->whereExists(fn ($q) => $q->from('follow_ups')
                ->whereColumn('follow_ups.customer_id', 'customers.id')
                ->whereIn('follow_ups.status', [FollowUpStatus::Pending->value, FollowUpStatus::Due->value]))
            ->count();

        return [
            'total' => $total,
            'active' => (int) ($byStatus[CustomerStatus::Active->value] ?? 0),
            'inactive' => (int) ($byStatus[CustomerStatus::Inactive->value] ?? 0),
            'follow_up' => $withFollowUp,
            'no_follow_up' => $total - $withFollowUp,
        ];
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'conversation', 'followUp', 'intent', 'sort', 'perPage');
        $this->resetPage();
    }

    public function render(CustomerDirectory $directory)
    {
        $organization = Auth::user()->organization;

        return view('livewire.customers.customer-index', [
            'organization' => $organization,
            'customers' => $directory->paginate($organization, [
                'search' => $this->search,
                'status' => $this->status,
                'conversation' => $this->conversation,
                'follow_up' => $this->followUp,
                'intent' => $this->intent,
                'sort' => $this->sort,
                'per_page' => $this->perPage,
            ]),
            'hasAnyCustomer' => $organization->customers()->exists(),
            'filtering' => ($this->search.$this->status.$this->conversation.$this->followUp.$this->intent) !== '',
            'customerStatuses' => CustomerStatus::cases(),
            'conversationStatuses' => ConversationStatus::cases(),
            'intents' => CustomerDirectory::INTENTS,
        ]);
    }
}
