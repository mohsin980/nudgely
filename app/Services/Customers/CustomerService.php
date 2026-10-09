<?php

namespace App\Services\Customers;

use App\Enums\Billing\LimitKey;
use App\Enums\CustomerStatus;
use App\Events\CustomerCreated;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Conversations\DuplicateCustomerException;
use App\Models\Customer;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits customers in the signed-in user's organization (never one taken from input).
 *
 * Emails are normalized (trimmed, lowercased) and unique per organization: a duplicate is
 * reported with the existing customer, never merged or created silently.
 */
class CustomerService
{
    /**
     * @param  array{first_name?: ?string, last_name?: ?string, email?: ?string, phone?: ?string, company?: ?string, notes?: ?string}  $input
     *
     * @throws ValidationException|DuplicateCustomerException
     */
    public function create(User $actor, array $input): Customer
    {
        $organization = $actor->organization ?? abort(403);
        $data = $this->validated($input, requireStatus: false);
        $this->assertUniqueEmail($organization->id, $data['email']);

        try {
            // The limit check and the insert happen under the organization's lock, so concurrent requests can't overshoot.
            $customer = app(EntitlementService::class)->guard($organization, LimitKey::Customers,
                fn () => $organization->customers()->create($data + ['status' => CustomerStatus::Active]));
        } catch (PlanLimitException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException) {
            // Two people adding the same customer at once: the database unique index decides.
            throw new DuplicateCustomerException($organization->customers()->where('email', $data['email'])->firstOrFail());
        }

        Log::info('Customer created.', ['organization_id' => $organization->id, 'customer_id' => $customer->id, 'user_id' => $actor->id]);
        CustomerCreated::dispatch($organization->id, $customer->id);

        return $customer;
    }

    /**
     * @param  array{first_name?: ?string, last_name?: ?string, email?: ?string, phone?: ?string, company?: ?string, notes?: ?string, status?: ?string}  $input
     *
     * @throws ValidationException|DuplicateCustomerException
     */
    public function update(User $actor, Customer $customer, array $input): Customer
    {
        if ($actor->organization_id === null || $actor->organization_id !== $customer->organization_id) {
            abort(404);
        }

        $data = $this->validated($input, requireStatus: true);

        if ($data['email'] !== $customer->email) {
            $this->assertUniqueEmail($customer->organization_id, $data['email'], ignoreId: $customer->id);
        }

        try {
            DB::transaction(fn () => $customer->update($data));
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateCustomerException(Customer::query()->where('organization_id', $customer->organization_id)->where('email', $data['email'])->firstOrFail());
        }

        Log::info('Customer updated.', ['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'user_id' => $actor->id]);

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{first_name: string, last_name: string, email: string, phone: ?string, company: ?string, notes: ?string, status?: CustomerStatus}
     *
     * @throws ValidationException
     */
    public function validated(array $input, bool $requireStatus): array
    {
        $clean = fn (string $key) => trim(preg_replace('/\s+/', ' ', (string) ($input[$key] ?? '')));
        $data = [
            'first_name' => $clean('first_name'),
            'last_name' => $clean('last_name'),
            'email' => Customer::normalizeEmail((string) ($input['email'] ?? '')),
            'phone' => $clean('phone') ?: null,
            'company' => $clean('company') ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $errors = [];

        if ($data['first_name'] === '' || mb_strlen($data['first_name']) > 100) {
            $errors['first_name'] = 'Enter a first name (up to 100 characters).';
        }

        if ($data['last_name'] === '' || mb_strlen($data['last_name']) > 100) {
            $errors['last_name'] = 'Enter a last name (up to 100 characters).';
        }

        if (mb_strlen($data['email']) > 254 || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($data['phone'] !== null && (! preg_match('/^\+?[0-9 ().\-]{7,25}$/', $data['phone']) || strlen(preg_replace('/\D/', '', $data['phone'])) < 7)) {
            $errors['phone'] = 'Enter a valid phone number.';
        }

        if ($data['company'] !== null && mb_strlen($data['company']) > 255) {
            $errors['company'] = 'The company name may be up to 255 characters.';
        }

        if ($data['notes'] !== null && mb_strlen($data['notes']) > 2000) {
            $errors['notes'] = 'Notes may be up to 2000 characters.';
        }

        if ($requireStatus) {
            $status = CustomerStatus::tryFrom((string) ($input['status'] ?? ''));
            $status === null ? $errors['status'] = 'Choose active or inactive.' : $data['status'] = $status;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * @throws DuplicateCustomerException
     */
    private function assertUniqueEmail(int $organizationId, string $email, ?int $ignoreId = null): void
    {
        $existing = Customer::query()
            ->where('organization_id', $organizationId)
            ->where('email', $email)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($existing !== null) {
            throw new DuplicateCustomerException($existing);
        }
    }
}
