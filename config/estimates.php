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

    /*
    |--------------------------------------------------------------------------
    | CSV estimate import limits
    |--------------------------------------------------------------------------
    */
    'import' => [
        'max_rows' => 5000,
        'max_bytes' => 5 * 1024 * 1024,
        'batch_size' => 100,
        // Uploaded files and the customer data in import rows are removed after this many days.
        'retention_days' => 30,
        // Uploads one business may start per 10 minutes.
        'uploads_per_10_minutes' => 10,
    ],
];
