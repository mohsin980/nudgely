<?php

namespace App\Services\Billing;

use App\Billing\PlanCatalog;
use App\Contracts\Billing\BillingProviderInterface;
use App\Exceptions\Billing\BillingException;
use App\Services\Billing\Providers\ManualBillingProvider;
use App\Services\Billing\Providers\StripeBillingProvider;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves the billing provider (config billing.provider): "stripe" or "manual".
 *
 * @method BillingProviderInterface driver(?string $driver = null)
 */
class BillingProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('billing.provider', 'manual');
    }

    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            throw new BillingException("Billing provider [{$driver}] is not supported.");
        }
    }

    protected function createManualDriver(): BillingProviderInterface
    {
        return new ManualBillingProvider;
    }

    protected function createStripeDriver(): BillingProviderInterface
    {
        return new StripeBillingProvider($this->config->get('services.stripe', []), $this->container->make(PlanCatalog::class));
    }
}
