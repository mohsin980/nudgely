<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Estimates
    |--------------------------------------------------------------------------
    |
    | currency: stored on each estimate. There is no organization currency setting
    | yet and no conversion: every amount is in the estimate's own currency.
    | first_number: the first estimate number an organization gets (EST-1001).
    | default_valid_days: "Valid until" suggested for a new estimate.
    | max_items: line items per estimate.
    |
    */

    'currency' => env('ESTIMATES_CURRENCY', 'USD'),

    'number_prefix' => 'EST-',

    'first_number' => 1001,

    'default_valid_days' => 30,

    'max_items' => 50,

];
