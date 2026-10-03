<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Adds customers to the signed-in user's organization (never one taken from input).
 */
class CustomerService
{
    /**
     * @throws ValidationException
     */
    public function create(User $actor, string $name, string $email): Customer
    {
        $organization = $actor->organization ?? abort(403);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $email = strtolower(trim($email));

        $errors = [];

        if ($name === '' || mb_strlen($name) > 255) {
            $errors['name'] = 'Enter the customer’s name.';
        }

        if (mb_strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($organization->customers()->where('email', $email)->exists()) {
            $errors['email'] = 'A customer with this email already exists.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $customer = $organization->customers()->create(['name' => $name, 'email' => $email]);

        Log::info('Customer created.', ['organization_id' => $organization->id, 'customer_id' => $customer->id, 'user_id' => $actor->id]);

        return $customer;
    }
}
