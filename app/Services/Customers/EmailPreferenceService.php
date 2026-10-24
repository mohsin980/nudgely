<?php

namespace App\Services\Customers;

use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpType;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Whether a customer may be emailed. The enforcement lives where emails are sent
 * (Customer::hasOptedOutOfEmail); this is the only place that changes it.
 */
class EmailPreferenceService
{
    public const SOURCE_LINK = 'link';

    public const SOURCE_STAFF = 'staff';

    public function __construct(private readonly FollowUpService $followUps) {}

    /**
     * The customer's own unsubscribe page. The signature is the only credential, and it never expires:
     * an old email must still work.
     */
    public function unsubscribeUrl(Customer $customer): string
    {
        return URL::signedRoute('unsubscribe.show', ['customer' => $customer->id]);
    }

    /**
     * Stop emailing the customer. Their open automated follow-ups are skipped. Doing it twice changes nothing.
     *
     * @return bool Whether the customer was newly opted out.
     */
    public function optOut(Customer $customer, string $source): bool
    {
        $changed = DB::transaction(function () use ($customer, $source) {
            $locked = Customer::query()->lockForUpdate()->find($customer->id);

            if ($locked === null || $locked->hasOptedOutOfEmail()) {
                return false;
            }

            $locked->forceFill(['email_opted_out_at' => now(), 'email_opted_out_source' => $source])->save();

            return true;
        });

        if (! $changed) {
            return false;
        }

        $customer->refresh();

        FollowUp::query()
            ->forOrganization($customer->organization_id)
            ->where('customer_id', $customer->id)
            ->where('type', FollowUpType::Automated)
            ->open()
            ->pluck('id')
            ->each(fn (int $id) => $this->followUps->skip($id, FollowUpSkipReason::OptedOut));

        Log::info('Customer opted out of email.', ['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'source' => $source]);

        return true;
    }

    /**
     * Allow email again (the customer asked to be emailed). Only a person at the business can do this.
     */
    public function optIn(User $actor, Customer $customer): bool
    {
        if ($actor->organization_id === null || $actor->organization_id !== $customer->organization_id) {
            abort(404);
        }

        if (! $customer->hasOptedOutOfEmail()) {
            return false;
        }

        $customer->forceFill(['email_opted_out_at' => null, 'email_opted_out_source' => null])->save();

        Log::info('Customer email opt-out removed.', ['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'user_id' => $actor->id]);

        return true;
    }
}
