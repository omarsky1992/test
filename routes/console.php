<?php

use App\Models\SyncRun;
use App\Services\BackupService;
use App\Services\Settings;
use App\Sync\CompanySync;
use App\Sync\FtthApiClient;
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

Artisan::command('company:keep-session', function (FtthApiClient $client) {
    try {
        $client->keepAlive() ? $this->info('Company session renewed.') : $this->line('No refresh token stored.');
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    return 0;
})->purpose('Renew the company sign-in with the stored refresh token so it never expires from idleness');

// EarthLink ends idle sign-ins after a while; renewing every 10 minutes keeps the refresh token valid.
Schedule::command('company:keep-session')->everyTenMinutes()->withoutOverlapping(5);

Artisan::command('whatsapp:scan', function (App\WhatsApp\Notifier $notifier, App\WhatsApp\Inbox $inbox) {
    $inbox->closeStuck();
    $alerts = $notifier->scanSecondaryExpiring();
    $messages = $notifier->scanSubscribers();
    $this->info("Queued {$alerts} staff alerts and {$messages} subscriber messages.");
})->purpose('Queue the WhatsApp alerts for staff and the automatic messages for subscribers');

Artisan::command('whatsapp:dispatch', function (App\WhatsApp\Outbox $outbox, App\WhatsApp\Gateway $gateway, Settings $settings) {
    // With the QR connection, nothing is tried while the phone is not linked: the queue waits.
    if ($settings->get('whatsapp.driver') !== 'meta' && ! ($gateway instanceof App\WhatsApp\WahaGateway && $gateway->isConnected())) {
        return 0;
    }
    [$sent, $failed] = $outbox->dispatch($gateway);
    if ($sent + $failed > 0) {
        $this->info("Sent {$sent}, failed {$failed}.");
    }

    return 0;
})->purpose('Send queued WhatsApp messages slowly from the linked phone');

Schedule::command('whatsapp:scan')->everyFifteenMinutes()->withoutOverlapping(10);
// In the background so the pauses between messages never hold up the other scheduled tasks.
Schedule::command('whatsapp:dispatch')->everyMinute()->withoutOverlapping(10)->runInBackground();
