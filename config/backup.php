<?php

return [
    // OAuth client from Google Cloud Console (type "Web application"). See docs/free-hosting.md.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'folder_name' => env('BACKUP_DRIVE_FOLDER', 'نسخ نظام المشتركين'),
    ],

    // Secret that an outside scheduler (GitHub Actions) sends to POST /internal/backup.
    'trigger_token' => env('BACKUP_TRIGGER_TOKEN'),

    // Backups older than this are deleted from the Drive folder.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    'pg_dump' => env('PG_DUMP_PATH', 'pg_dump'),

    // Where the server's own daily dumps are mounted (the backup service in deploy/docker-compose.yml).
    'server_dir' => env('BACKUP_SERVER_DIR', '/backups'),
];
