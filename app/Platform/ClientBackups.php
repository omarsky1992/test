<?php

namespace App\Platform;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The daily copy of the platform database and of every client's database except client 1 (whose
 * own daily dumps are made by the server's backup service, as before). Custom-format dumps
 * (restorable with pg_restore), one folder per database, the newest `platform.backup_keep` kept.
 * Read-only for the databases: pg_dump never writes to them.
 */
class ClientBackups
{
    /** @return array<string, string> database => file written (or the error) */
    public function run(): array
    {
        $databases = [(string) config('database.connections.platform.database')];
        foreach (Tenant::orderBy('id')->get() as $tenant) {
            if ($tenant->database !== app(TenantManager::class)->homeDatabase()) {
                $databases[] = $tenant->database;
            }
        }
        $results = [];
        foreach (array_unique($databases) as $database) {
            try {
                $results[$database] = $this->dump($database);
            } catch (\Throwable $e) {
                report($e);
                $results[$database] = 'failed: '.$e->getMessage();
            }
        }

        return $results;
    }

    private function dump(string $database): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $database)) {
            throw new RuntimeException("Invalid database name: {$database}");
        }
        $dir = rtrim((string) config('platform.backup_dir'), '/').'/'.$database;
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $file = $dir.'/'.$database.'-'.now()->setTimezone(Subscription::tz())->format('Y-m-d_Hi').'.dump';
        $db = config('database.connections.platform');
        // The password goes through the environment, never the command line.
        $result = Process::env(['PGPASSWORD' => (string) $db['password'], 'PGCONNECT_TIMEOUT' => '30', 'PGSSLMODE' => (string) ($db['sslmode'] ?? 'prefer')])
            ->timeout(1800)
            ->run([config('backup.pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file='.$file,
                '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'], $database]);
        if ($result->failed() || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new RuntimeException(mb_strimwidth(trim($result->errorOutput()), 0, 300, '…'));
        }
        // Keep the newest few.
        $files = glob($dir.'/*.dump') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, (int) config('platform.backup_keep'))) as $old) {
            @unlink($old);
        }

        return $file;
    }
}
