<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerStatus;
use App\Exceptions\Conversations\DuplicateCustomerException;
use App\Models\Customer;
use App\Services\Customers\CustomerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create (/customers/create) or edit (/customers/{id}/edit) a customer. CustomerService validates
 * and saves; a duplicate email links to the existing customer instead of being merged.
 */
#[Layout('components.layouts.app')]
class CustomerForm extends Component
{
    #[Locked]
    public ?int $customerId = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $company = '';

    public string $notes = '';

    public string $status = 'active';

    #[Locked]
    public ?int $duplicateCustomerId = null;

    public function mount(?int $customerId = null): void
    {
        if ($customerId === null) {
            $this->authorize('create', Customer::class);

            return;
        }

        $customer = $this->findCustomer($customerId);
        $this->authorize('update', $customer);

        $this->customerId = $customer->id;
        $this->fill([
            'first_name' => (string) $customer->first_name,
            'last_name' => (string) $customer->last_name,
            'email' => $customer->email,
            'phone' => (string) $customer->phone,
            'company' => (string) $customer->company,
            'notes' => (string) $customer->notes,
            'status' => $customer->status?->value ?? CustomerStatus::Active->value,
        ]);
    }

    public function save(CustomerService $customers): void
    {
        $this->resetErrorBag();
        $this->duplicateCustomerId = null;
        $input = $this->only(['first_name', 'last_name', 'email', 'phone', 'company', 'notes', 'status']);

        try {
            if ($this->customerId === null) {
                $this->authorize('create', Customer::class);
                $customer = $customers->create(Auth::user(), $input);
            } else {
                $customer = $this->findCustomer($this->customerId);
                $this->authorize('update', $customer);
                $customers->update(Auth::user(), $customer, $input);
            }
        } catch (DuplicateCustomerException $e) {
            $this->duplicateCustomerId = $e->existing->id;
            $this->addError('email', 'Customer already exists.');

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        session()->flash('customer-status', $this->customerId === null ? 'Customer added.' : 'Customer updated.');
        $this->redirectRoute('customers.show', $customer->id, navigate: true);
    }

    public function render()
    {
        return view('livewire.customers.customer-form', ['statuses' => CustomerStatus::cases()])
            ->title($this->customerId ? 'Edit customer' : 'Add customer');
    }

    private function findCustomer(int $customerId): Customer
    {
        return Customer::query()->where('organization_id', Auth::user()->organization_id ?? abort(403))->whereKey($customerId)->first() ?? abort(404);
    }
}
