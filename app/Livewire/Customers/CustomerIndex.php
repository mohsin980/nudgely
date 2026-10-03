<?php

namespace App\Livewire\Customers;

use App\Enums\ConversationStatus;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Customers\CustomerDirectory;
use Illuminate\Support\Facades\Auth;
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
