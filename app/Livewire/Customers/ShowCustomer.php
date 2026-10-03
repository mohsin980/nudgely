<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\ManagesFollowUps;
use App\Models\Customer;
use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A customer's conversations and follow-ups (upcoming and history).
 */
#[Layout('components.layouts.app')]
class ShowCustomer extends Component
{
    use ManagesFollowUps;

    #[Locked]
    public int $customerId;

    public function mount(int $customerId): void
    {
        $this->authorize('viewAny', FollowUp::class);
        $this->customerId = $customerId;
        $this->customer; // 404 for another organization's customer
    }

    /**
     * Looked up inside the user's organization.
     */
    #[Computed]
    public function customer(): Customer
    {
        return $this->organization()->customers()->whereKey($this->customerId)->first() ?? abort(404);
    }

    /**
     * @return Collection<int, FollowUp>
     */
    #[Computed]
    public function followUps(): Collection
    {
        return $this->customer->followUps()
            ->where('organization_id', $this->organization()->id)
            ->with('assignee:id,name')
            ->orderByRaw("case when status in ('pending', 'due') then 0 else 1 end")
            ->orderByRaw("case when status in ('pending', 'due') then due_at end asc")
            ->latest('updated_at')
            ->limit(50)
            ->get();
    }

    public function render()
    {
        return view('livewire.customers.show-customer', [
            'organization' => $this->organization(),
            'conversations' => $this->customer->conversations()->where('organization_id', $this->organization()->id)->latest('last_message_at')->limit(20)->get(),
        ])->title($this->customer->name);
    }

    protected function followUpTarget(): array
    {
        return [$this->customer, null];
    }

    protected function followUpsChanged(): void
    {
        unset($this->followUps);
    }
}
