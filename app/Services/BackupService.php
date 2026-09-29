<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Dumps the whole database with pg_dump and uploads it to the connected Google Drive.
 * Every attempt, successful or not, is recorded in backup_runs.
 */
class BackupService
{
    public function __construct(private GoogleDrive $drive, private Audit $audit)
    {
    }

    public function run(string $trigger = 'manual'): BackupRun
    {
        $lock = Cache::lock('database-backup', 1800);
        if (! $lock->get()) {
            throw new BusinessRuleException('يوجد نسخ احتياطي قيد التنفيذ الآن.');
        }

        $run = BackupRun::create([
            'trigger' => $trigger,
            'status' => 'running',
            'triggered_by' => Auth::id(),
            'started_at' => now(),
        ]);
        $path = null;

        try {
            $name = 'subs-backup-'.now()->format('Y-m-d_Hi').'.dump';
            $path = $this->dump($name);
            $file = $this->drive->upload($path, $name);
            $deleted = rescue(fn () => $this->drive->deleteOlderThan(config('backup.keep_days')), 0, report: false);

            $run->update([
                'status' => 'success',
                'file_name' => $name,
                'size_bytes' => filesize($path),
                'drive_file_id' => $file['id'],
                'drive_link' => $file['link'],
                'finished_at' => now(),
            ]);
            $this->audit->log('backup.completed', $run, null, ['file' => $name, 'size' => $run->size_bytes, 'old_deleted' => $deleted], source: Auth::check() ? 'web' : 'system');
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => mb_strimwidth($e->getMessage(), 0, 1000, '…'), 'finished_at' => now()]);
            $this->audit->log('backup.failed', $run, null, ['error' => $run->error], source: Auth::check() ? 'web' : 'system');
            report($e);
        } finally {
            if ($path && is_file($path)) {
                @unlink($path);
            }
            $lock->release();
        }

        return $run->fresh();
    }

    public function lastSuccess(): ?BackupRun
    {
        return BackupRun::where('status', 'success')->latest('started_at')->first();
    }

    /**
     * Custom-format dump (compressed, restorable with pg_restore). Credentials go through the
     * environment, never the command line.
     */
    private function dump(string $name): string
    {
        $db = config('database.connections.'.config('database.default'));
        $path = storage_path('app/private/'.$name);
        @mkdir(dirname($path), 0700, true);

        $args = [config('backup.pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file='.$path];
        $env = ['PGCONNECT_TIMEOUT' => '30'];
        if (filled($db['url'] ?? null)) {
            $args[] = '--dbname='.$db['url'];
        } else {
            $args = [...$args, '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'], $db['database']];
            $env['PGPASSWORD'] = (string) $db['password'];
            $env['PGSSLMODE'] = (string) ($db['sslmode'] ?? 'prefer');
        }

        $result = Process::env($env)->timeout(900)->run($args);
        if ($result->failed() || ! is_file($path) || filesize($path) === 0) {
            throw new BusinessRuleException('فشل إنشاء النسخة: '.mb_strimwidth(trim($result->errorOutput()), 0, 300, '…'));
        }

        return $path;
    }
}
