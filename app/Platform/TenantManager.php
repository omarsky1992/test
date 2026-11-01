<?php

namespace App\Platform;

use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Which client a request or a command works for, and the switch to that client's database. Client 1
 * (the original system) is the home database; another client's request runs entirely on its own
 * database, its own files and none of the server-wide connections (WhatsApp, AI, server backups)
 * that belong to client 1.
 */
class TenantManager
{
    private ?Tenant $current = null;

    private bool $platform = false;

    /** @var array<string, mixed>|null the server-wide settings of client 1, restored on the way back */
    private ?array $homeConfig = null;

    public function homeDatabase(): string
    {
        return (string) config('platform.home_database');
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    /** Whether the active database is client 1's. */
    public function isHome(): bool
    {
        return config('database.connections.'.config('database.default').'.database') === $this->homeDatabase();
    }

    /** Client 1's own address and the server's internal names. */
    public function isHomeHost(string $host): bool
    {
        $host = self::normalizeHost($host);
        $home = self::normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === $home || in_array($host, config('platform.home_hosts', []), true) || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Switches to the client of this host. Returns false for a host that belongs to no client (the
     * caller answers 404); client 1's hosts work even before the platform database exists.
     */
    public function identify(string $host): bool
    {
        if ($this->isPlatformHost($host)) {
            $this->activatePlatform();

            return true;
        }
        if ($this->isHomeHost($host)) {
            $this->activate(null);

            return true;
        }
        try {
            $tenant = Tenant::where('domain', self::normalizeHost($host))->where('status', '<>', 'archived')->first();
        } catch (QueryException) {
            return false; // no platform database yet: only client 1 exists
        }
        if ($tenant === null) {
            return false;
        }
        $this->activate($tenant);

        return true;
    }

    /** For commands: the client named by its slug (TENANT=…), or an error. */
    public function activateSlug(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $this->activate($tenant);

        return $tenant;
    }

    /**
     * The clients whose scheduled work runs (archived ones never), or null before the platform
     * database exists (only client 1 then).
     *
     * @return list<string>|null
     */
    public function runnableSlugs(): ?array
    {
        try {
            return Tenant::where('status', '<>', 'archived')->orderBy('id')->pluck('slug')->all();
        } catch (QueryException) {
            return null;
        }
    }

    /** null = client 1. Everything that caches data of the previous client is dropped. */
    public function activate(?Tenant $tenant): void
    {
        $this->platform = false;
        $this->current = $tenant;
        $this->switchTo($tenant?->database ?? $this->homeDatabase(), $tenant === null || $tenant->database === $this->homeDatabase() ? null : "tenants/{$tenant->slug}");
    }

    /**
     * The platform owner's panel: the default connection points at the platform database, so nothing
     * there can read a client's data by mistake (sessions and cache live there too).
     */
    public function activatePlatform(): void
    {
        $this->platform = true;
        $this->current = null;
        $this->switchTo((string) config('database.connections.platform.database'), 'platform');
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function isPlatformHost(string $host): bool
    {
        return self::normalizeHost($host) === self::normalizeHost((string) config('platform.admin_host'));
    }

    /** $folder: null = client 1's own storage folders. */
    private function switchTo(string $database, ?string $folder): void
    {
        $connection = config('database.default');
        if (config("database.connections.{$connection}.database") === $database) {
            return;
        }
        $this->homeConfig ??= $this->serverWide();

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        // Files: client 1 keeps its folders; another client (or the platform) gets its own.
        config([
            'filesystems.disks.local.root' => $folder === null ? storage_path('app/private') : storage_path("app/{$folder}/private"),
            'filesystems.disks.public.root' => $folder === null ? storage_path('app/public') : storage_path("app/{$folder}/public"),
        ]);
        Storage::forgetDisk(['local', 'public']);

        // Client 1's WhatsApp phones, AI keys and server dumps are never another client's.
        config($folder === null ? $this->homeConfig : array_map(fn () => null, $this->homeConfig));

        $this->forgetCaches();
    }

    /** @return array<string, mixed> */
    private function serverWide(): array
    {
        $keys = [
            'whatsapp.waha.api_key', 'whatsapp.waha.webhook_secret', 'whatsapp.waha_notify.api_key',
            'whatsapp.access_token', 'whatsapp.phone_number_id', 'whatsapp.app_secret', 'whatsapp.verify_token',
            'whatsapp.ai.api_key', 'whatsapp.transcribe.api_key', 'backup.server_dir',
        ];

        return array_combine($keys, array_map(fn (string $key) => config($key), $keys));
    }

    /** Caches, sessions, settings and permissions read from the previous database. */
    private function forgetCaches(): void
    {
        $app = app();
        if ($app->resolved('cache')) {
            $app->make('cache')->forgetDriver();
        }
        if ($app->resolved('session')) {
            $app->make('session')->forgetDrivers();
        }
        foreach (['cache.store', 'session.store', RateLimiter::class] as $instance) {
            $app->forgetInstance($instance);
        }
        // A user signed in on one client is never signed in on another.
        if ($app->resolved('auth')) {
            $app->make('auth')->forgetGuards();
        }
        // This system's own per-request services that read the database (never the framework's:
        // Filament keeps the current panel in its own).
        foreach ([Settings::class, Audit::class, Ledger::class] as $service) {
            $app->forgetInstance($service);
        }
        Facade::clearResolvedInstances();
        if ($app->resolved(PermissionRegistrar::class)) {
            $registrar = $app->make(PermissionRegistrar::class);
            $registrar->initializeCache();
            $registrar->clearPermissionsCollection();
        }
    }

    public static function normalizeHost(string $host): string
    {
        return strtolower(rtrim((string) preg_replace('/:\d+$/', '', trim($host)), '.'));
    }
}
