<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Commercial Stack Feature Flag
    |--------------------------------------------------------------------------
    |
    | This flag controls loading of commercial modules (CRM, FMS, POS, IMS,
    | LMS, SCM, Sales, Procurement, Helpdesk, Asset) from route groups that
    | are outside the current implementation scope.
    |
    */

    'commercial_stack' => filter_var(env('FEATURE_COMMERCIAL_STACK', false), FILTER_VALIDATE_BOOLEAN),

];
