<?php

return [
    // Client 1's database: the original system. Captured from the environment, never switched.
    'home_database' => env('DB_DATABASE', 'laravel'),

    // Hosts that always mean client 1 besides APP_URL's: the services inside the server (WAHA posts
    // its webhook to http://app:8080) and local development.
    'home_hosts' => array_filter(array_map('trim', explode(',', (string) env('PLATFORM_HOME_HOSTS', 'app,localhost,127.0.0.1')))),
];
