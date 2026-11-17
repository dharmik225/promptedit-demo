<?php

return [

    /*
    | Accounts created by `php artisan db:seed`. Passwords live only in .env.
    | An account is skipped when its email or password is empty.
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'test_client' => [
        'name' => env('TEST_CLIENT_NAME', 'Test Client'),
        'email' => env('TEST_CLIENT_EMAIL'),
        'password' => env('TEST_CLIENT_PASSWORD'),
    ],

];
