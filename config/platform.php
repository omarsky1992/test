<?php

$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

return [
    // Client 1's database: the original system. Captured from the environment, never switched.
    'home_database' => env('DB_DATABASE', 'laravel'),

    // Hosts that always mean client 1 besides APP_URL's: the services inside the server (WAHA posts
    // its webhook to http://app:8080) and local development.
    'home_hosts' => array_filter(array_map('trim', explode(',', (string) env('PLATFORM_HOME_HOSTS', 'app,localhost,127.0.0.1')))),

    // The platform owner's panel: its own address, its own database, its own accounts.
    'admin_host' => env('PLATFORM_HOST', 'platform.'.$appHost),

    // New clients get «{slug}.{base_domain}».
    'base_domain' => env('PLATFORM_BASE_DOMAIN', $appHost),

    // Addresses no client may take.
    'reserved_slugs' => ['platform', 'www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'main', 'static', 'assets', 'cdn', 'waha', 'db'],

    // Daily dumps of the platform database and of the clients' databases (client 1 has its own).
    'backup_dir' => env('PLATFORM_BACKUP_DIR', storage_path('app/platform-backups')),
    'backup_keep' => (int) env('PLATFORM_BACKUP_KEEP', 7),

    // «قريب من الانتهاء» from this many days before the end.
    'expiring_days' => 7,
];
