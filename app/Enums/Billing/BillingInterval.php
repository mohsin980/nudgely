<?php

namespace App\Enums\Billing;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';
}
