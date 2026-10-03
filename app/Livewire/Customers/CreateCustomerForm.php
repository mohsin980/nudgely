<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use App\Services\Customers\CustomerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * "Add Customer" form; on success it opens the new customer's page.
 */
class CreateCustomerForm extends Component
{
    public string $name = '';

    public string $email = '';

    public function save(CustomerService $customers): void
    {
        $this->authorize('create', Customer::class);
        $this->resetErrorBag();

        try {
            $customer = $customers->create(Auth::user(), $this->name, $this->email);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->redirectRoute('customers.show', $customer->id, navigate: true);
    }

    public function render()
    {
        return view('livewire.customers.create-customer-form');
    }
}
