<?php

use App\Models\SyncRun;
use App\Services\BackupService;
use App\Services\Settings;
use App\Sync\CompanySync;
use Carbon\CarbonImmutable;
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

Artisan::command('company:sync {--scheduled : Run only when automatic sync is on and its interval has passed}', function (CompanySync $sync, Settings $settings) {
    if ($this->option('scheduled')) {
        $last = SyncRun::whereIn('trigger', ['schedule', 'cli'])->latest('started_at')->value('started_at');
        $due = $last === null || CarbonImmutable::parse($last)->addMinutes(max(15, (int) $settings->get('sync.interval_minutes')))->subMinute()->isPast();
        if (! $settings->get('sync.enabled') || ! $due) {
            return 0;
        }
    }
    $run = $sync->run($this->option('scheduled') ? 'schedule' : 'cli');
    $run->status === 'success'
        ? $this->info('Synced: '.json_encode(array_diff_key($run->stats, ['errors' => 1])))
        : $this->error("Sync failed: {$run->error}");

    return $run->status === 'success' ? 0 : 1;
})->purpose('Update subscribers from the company site and detect renewals');

Schedule::command('company:sync --scheduled')->everyFiveMinutes()->withoutOverlapping(30);
