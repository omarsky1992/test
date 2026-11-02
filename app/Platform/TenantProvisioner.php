<?php

namespace App\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds a client: its own new database with the same tables, the starting reference data and one
 * manager account. Nothing of any other client is read or copied. If any step fails, the new
 * (still empty) database and its row are removed, so a retry starts clean.
 */
class TenantProvisioner
{
    public function __construct(private PlatformAudit $audit) {}

    /**
     * @param  array{name: string, slug: string, owner_name?: ?string, phone?: ?string, username: string, password: string, plan?: ?string, notes?: ?string, status?: string, starts_at: CarbonImmutable, ends_at?: ?CarbonImmutable, trial_ends_at?: ?CarbonImmutable, grace_days?: int}  $data
     */
    public function create(array $data): Tenant
    {
        $slug = $this->validSlug($data['slug']);
        $database = $this->databaseName($slug);
        $domain = $slug.'.'.TenantManager::normalizeHost((string) config('platform.base_domain'));
        if (Tenant::where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['slug' => 'هذا الرابط مستخدم لعميل آخر.']);
        }
        if (strlen($data['password']) < 8) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور 8 أحرف على الأقل.']);
        }

        @set_time_limit(600); // building a database takes a little while inside a web request
        $this->createDatabase($database);
        $tenant = null;
        try {
            $tenant = Tenant::create([
                'slug' => $slug, 'name' => $data['name'], 'owner_name' => $data['owner_name'] ?? null, 'phone' => $data['phone'] ?? null,
                'domain' => $domain, 'database' => $database, 'status' => $data['status'] ?? 'active', 'plan' => $data['plan'] ?? null,
                'notes' => $data['notes'] ?? null, 'subscription_starts_at' => $data['starts_at'], 'subscription_ends_at' => $data['ends_at'] ?? null,
                'trial_ends_at' => $data['trial_ends_at'] ?? null, 'grace_days' => (int) ($data['grace_days'] ?? 0),
            ]);
            // In the client's own process (TENANT=slug), so nothing of this request's client leaks in.
            // The account details travel in the environment, never on the command line.
            $result = Process::path(base_path())->timeout(600)->env([
                'TENANT' => $slug,
                'PROVISION_NAME' => $data['owner_name'] ?: $data['name'],
                'PROVISION_USERNAME' => $data['username'],
                'PROVISION_PASSWORD' => $data['password'],
                'PROVISION_PHONE' => (string) ($data['phone'] ?? ''),
                'PROVISION_COMPANY' => $data['name'],
            ])->run([Php::binary(), 'artisan', 'platform:provision', '--no-interaction']);
            if ($result->failed()) {
                throw new \RuntimeException('Provisioning failed: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 500));
            }
        } catch (\Throwable $e) {
            $tenant?->delete();
            $this->dropNewDatabase($database);
            throw $e;
        }

        app(TenantStats::class)->refresh($tenant);
        $this->audit->log('tenant.created', $tenant, null, $tenant->only(['slug', 'name', 'domain', 'database', 'status', 'subscription_starts_at', 'subscription_ends_at', 'trial_ends_at']) + ['manager' => $data['username']]);

        return $tenant;
    }

    public function validSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (! preg_match('/^[a-z][a-z0-9-]{1,28}[a-z0-9]$/', $slug) || str_contains($slug, '--')) {
            throw ValidationException::withMessages(['slug' => 'الرابط: أحرف إنكليزية صغيرة وأرقام وشرطة، من 3 إلى 30 حرفاً، يبدأ بحرف.']);
        }
        if (in_array($slug, config('platform.reserved_slugs', []), true) || Tenant::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'هذا الرابط محجوز أو مستخدم.']);
        }

        return $slug;
    }

    private function databaseName(string $slug): string
    {
        $name = substr(app(TenantManager::class)->homeDatabase(), 0, 30).'_t_'.str_replace('-', '_', $slug);
        if (Tenant::where('database', $name)->exists() || $this->server()->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$name])) {
            throw ValidationException::withMessages(['slug' => 'يوجد مسبقاً قاعدة بيانات بهذا الاسم، اختر رابطاً آخر.']);
        }

        return $name;
    }

    private function createDatabase(string $name): void
    {
        $this->server()->statement("CREATE DATABASE \"{$name}\"");
    }

    /** Only the database this call has just created (still empty of any real data). */
    private function dropNewDatabase(string $name): void
    {
        rescue(fn () => $this->server()->statement("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)"), report: false);
    }

    /** A connection to the server's maintenance database, outside any transaction. */
    private function server()
    {
        config(['database.connections.platform_server' => ['database' => 'postgres'] + config('database.connections.platform')]);

        return DB::connection('platform_server');
    }
}
