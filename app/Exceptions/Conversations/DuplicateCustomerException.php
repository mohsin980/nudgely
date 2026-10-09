<?php

namespace App\Exceptions\Conversations;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * The email already belongs to a customer in this organization. Customers are never merged
 * silently; the UI offers a link to the existing customer instead.
 */
class DuplicateCustomerException extends ValidationException
{
    public function __construct(public readonly Customer $existing)
    {
        parent::__construct(validator([], []));
        $this->validator->errors()->add('email', 'Customer already exists.');
    }
}
