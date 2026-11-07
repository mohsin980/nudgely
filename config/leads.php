<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Won / Lost reminders
    |--------------------------------------------------------------------------
    |
    | A lead that is Qualified or Quoted for this many days without becoming Won
    | or Lost reminds the person handling it (or the owner and managers) to
    | record the outcome, then again every "every_days", up to "max" times.
    |
    */

    'outcome_reminders' => [
        'after_days' => (int) env('LEAD_OUTCOME_REMINDER_DAYS', 7),
        'every_days' => 7,
        'max' => 3,
    ],

];
