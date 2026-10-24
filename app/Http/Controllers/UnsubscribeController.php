<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Customers\EmailPreferenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * A customer stops the emails they get from a business. No account: the signed link in each email is the credential.
 */
class UnsubscribeController extends Controller
{
    /**
     * Only shows the page. Opening a link must not unsubscribe anyone, because mail scanners open every link.
     */
    public function show(Customer $customer): View
    {
        return $this->page($customer, done: $customer->hasOptedOutOfEmail());
    }

    public function store(Customer $customer, EmailPreferenceService $preferences): View
    {
        $preferences->optOut($customer, EmailPreferenceService::SOURCE_LINK);

        return $this->page($customer, done: true);
    }

    private function page(Customer $customer, bool $done): View
    {
        return view('unsubscribe', [
            'business' => $customer->organization->name,
            'address' => $this->masked($customer->email),
            'done' => $done,
        ]);
    }

    /**
     * jane.smith@example.com → ja•••@example.com, so the page does not reveal a full address to whoever holds the link.
     */
    private function masked(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 2).'•••@'.$domain;
    }
}
