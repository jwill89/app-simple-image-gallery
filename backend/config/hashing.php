<?php

/*
|--------------------------------------------------------------------------
| Password hashing
|--------------------------------------------------------------------------
|
| Passwords are hashed with argon2id at 64 MiB, one pass and four lanes, which
| is the least the security standard allows. Sign-in rehashes a password whose
| hash was made with other settings (Laravel compares them with
| password_needs_rehash), so raising a number here moves every account to it
| the next time it signs in. Only the tests lower the numbers, in phpunit.xml.
|
*/

return [

    'driver' => 'argon2id',

    // Laravel reads the argon2id settings from the "argon" key.
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'time' => (int) env('ARGON_TIME', 1),
        'threads' => (int) env('ARGON_THREADS', 4),
        // Off on purpose: an account's stored hash may still be bcrypt, and checking it
        // must work for the sign-in that replaces it. With this on, the check throws.
        'verify' => false,
    ],

];
