<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Password Policy Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines the password policy for the application
    | including complexity requirements, expiration, and history tracking.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Password Complexity Requirements
    |--------------------------------------------------------------------------
    |
    | Define the minimum requirements for password complexity.
    |
    */
    'min_length' => env('PASSWORD_MIN_LENGTH', 8),
    'max_length' => env('PASSWORD_MAX_LENGTH', 128),
    'require_uppercase' => env('PASSWORD_REQUIRE_UPPERCASE', true),
    'require_lowercase' => env('PASSWORD_REQUIRE_LOWERCASE', true),
    'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', true),
    'require_special_chars' => env('PASSWORD_REQUIRE_SPECIAL_CHARS', true),
    'special_chars' => env('PASSWORD_SPECIAL_CHARS', '!@#$%^&*()_+-=[]{}|;:,.<>?'),

    /*
    |--------------------------------------------------------------------------
    | Password History
    |--------------------------------------------------------------------------
    |
    | Define how many previous passwords to remember to prevent reuse.
    |
    */
    'history_count' => env('PASSWORD_HISTORY_COUNT', 5),

    /*
    |--------------------------------------------------------------------------
    | Password Expiration
    |--------------------------------------------------------------------------
    |
    | Define the password expiration policy in days.
    | Set to null to disable password expiration.
    |
    */
    'expiration_days' => env('PASSWORD_EXPIRATION_DAYS', 90),
    'warning_days' => env('PASSWORD_WARNING_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Password Breach Checking
    |--------------------------------------------------------------------------
    |
    | Enable/disable checking passwords against known breach databases.
    | Uses the Have I Been Pwned API.
    |
    */
    'check_breached' => env('PASSWORD_CHECK_BREACHED', true),
    'hibp_api_url' => env('HIBP_API_URL', 'https://api.pwnedpasswords.com/range/'),

    /*
    |--------------------------------------------------------------------------
    | Force Password Change
    |--------------------------------------------------------------------------
    |
    | Force users to change password on first login or after reset.
    |
    */
    'force_change_on_reset' => env('PASSWORD_FORCE_CHANGE_ON_RESET', true),
    'force_change_on_first_login' => env('PASSWORD_FORCE_CHANGE_ON_FIRST_LOGIN', false),

    /*
    |--------------------------------------------------------------------------
    | Account Lockout
    |--------------------------------------------------------------------------
    |
    | Number of failed password attempts before account lockout.
    |
    */
    'max_attempts' => env('PASSWORD_MAX_ATTEMPTS', 5),
    'lockout_minutes' => env('PASSWORD_LOCKOUT_MINUTES', 30),
];
