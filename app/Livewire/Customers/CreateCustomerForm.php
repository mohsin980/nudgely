<?php

namespace App\Livewire\Customers;

/**
 * The dashboard's inline "Add Customer": the same form and rules as /customers/create.
 */
class CreateCustomerForm extends CustomerForm
{
    public function mount(?int $customerId = null): void
    {
        parent::mount();
    }

    public function render()
    {
        return view('livewire.customers.create-customer-form');
    }
}
