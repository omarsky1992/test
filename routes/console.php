<?php

use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('backup:run', function (BackupService $backups) {
    $run = $backups->run('cli');
    $run->status === 'success'
        ? $this->info("Backup uploaded: {$run->file_name}")
        : $this->error("Backup failed: {$run->error}");

    return $run->status === 'success' ? 0 : 1;
})->purpose('Dump the database and upload it to Google Drive');

// On servers that run the scheduler (the Docker Compose setup). Free hosts use the GitHub Actions trigger instead.
Schedule::command('backup:run')->dailyAt('03:00')->timezone('Asia/Baghdad')->withoutOverlapping();
