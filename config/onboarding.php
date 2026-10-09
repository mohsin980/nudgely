<?php

/*
|--------------------------------------------------------------------------
| Onboarding
|--------------------------------------------------------------------------
|
| Data for the first-run experience. Business types drive which automation templates are
| suggested (keys of App\Services\Automation\AutomationTemplates::all()); there is no
| industry-specific logic anywhere else.
|
*/

return [

    // The template offered as the first automation (shown with a full review before activating).
    'first_automation_template' => 'follow_up_after_estimate',

    'business_types' => [
        'hvac' => ['label' => 'HVAC', 'templates' => ['follow_up_after_estimate', 'interested', 'wants_callback']],
        'plumbing' => ['label' => 'Plumbing', 'templates' => ['follow_up_after_estimate', 'ready_to_book', 'wants_callback']],
        'roofing' => ['label' => 'Roofing', 'templates' => ['follow_up_after_estimate', 'price_objection', 'estimate_accepted']],
        'electrical' => ['label' => 'Electrical', 'templates' => ['follow_up_after_estimate', 'ready_to_book', 'wants_callback']],
        'landscaping' => ['label' => 'Landscaping', 'templates' => ['follow_up_after_estimate', 'interested', 'estimate_accepted']],
        'cleaning' => ['label' => 'Cleaning', 'templates' => ['estimate_follow_up', 'interested', 'ready_to_book']],
        'pest_control' => ['label' => 'Pest Control', 'templates' => ['follow_up_after_estimate', 'wants_callback', 'estimate_accepted']],
        'general_contractor' => ['label' => 'General Contractor', 'templates' => ['follow_up_after_estimate', 'price_objection', 'estimate_accepted']],
        'other' => ['label' => 'Other', 'templates' => ['follow_up_after_estimate', 'ready_to_book', 'interested']],
    ],

    // Where a business in this state most likely is, to suggest a timezone (editable).
    'state_timezones' => [
        'AL' => 'America/Chicago', 'AK' => 'America/Anchorage', 'AZ' => 'America/Phoenix', 'AR' => 'America/Chicago', 'CA' => 'America/Los_Angeles',
        'CO' => 'America/Denver', 'CT' => 'America/New_York', 'DE' => 'America/New_York', 'FL' => 'America/New_York', 'GA' => 'America/New_York',
        'HI' => 'Pacific/Honolulu', 'ID' => 'America/Boise', 'IL' => 'America/Chicago', 'IN' => 'America/Indiana/Indianapolis', 'IA' => 'America/Chicago',
        'KS' => 'America/Chicago', 'KY' => 'America/New_York', 'LA' => 'America/Chicago', 'ME' => 'America/New_York', 'MD' => 'America/New_York',
        'MA' => 'America/New_York', 'MI' => 'America/Detroit', 'MN' => 'America/Chicago', 'MS' => 'America/Chicago', 'MO' => 'America/Chicago',
        'MT' => 'America/Denver', 'NE' => 'America/Chicago', 'NV' => 'America/Los_Angeles', 'NH' => 'America/New_York', 'NJ' => 'America/New_York',
        'NM' => 'America/Denver', 'NY' => 'America/New_York', 'NC' => 'America/New_York', 'ND' => 'America/Chicago', 'OH' => 'America/New_York',
        'OK' => 'America/Chicago', 'OR' => 'America/Los_Angeles', 'PA' => 'America/New_York', 'RI' => 'America/New_York', 'SC' => 'America/New_York',
        'SD' => 'America/Chicago', 'TN' => 'America/Chicago', 'TX' => 'America/Chicago', 'UT' => 'America/Denver', 'VT' => 'America/New_York',
        'VA' => 'America/New_York', 'WA' => 'America/Los_Angeles', 'WV' => 'America/New_York', 'WI' => 'America/Chicago', 'WY' => 'America/Denver',
        'DC' => 'America/New_York',
    ],
];
