<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Team Invitations
    |--------------------------------------------------------------------------
    |
    | An invitation link works once and only until it expires. A resend replaces it.
    |
    */

    'invitation_days' => (int) env('TEAM_INVITATION_DAYS', 7),

    'max_open_invitations' => 50,

    // Business logos are stored privately and served by LogoController.
    'logo_disk' => env('TEAM_LOGO_DISK', 'local'),

];
