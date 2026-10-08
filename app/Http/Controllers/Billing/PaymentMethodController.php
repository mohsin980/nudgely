<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\Billing\BillingException;
use App\Http\Controllers\Controller;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Update Payment Method" links (banners, notifications, emails): sends the owner to the provider's
 * hosted billing portal, where cards are changed. Owner-only; QuoteFollow never sees card details.
 */
class PaymentMethodController extends Controller
{
    public function __invoke(Request $request, BillingService $billing): RedirectResponse
    {
        try {
            return redirect()->away($billing->portalUrl($request->user(), route('settings.billing', ['portal' => 1])));
        } catch (BillingException $e) {
            return redirect()->route('settings.billing')->with('billing-error', $e->getMessage());
        }
    }
}
