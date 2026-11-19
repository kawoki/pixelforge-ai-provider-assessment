<?php

declare(strict_types=1);

/*
| Demo accounts created by `php artisan db:seed` (DemoSeeder). Unset: "password" locally,
| a random password (printed once) anywhere else.
*/

return [
    'user_password' => env('DEMO_USER_PASSWORD'),
    'admin_password' => env('DEMO_ADMIN_PASSWORD'),
];
