<?php

use App\Models\Account;
use App\Models\Debt;
use App\Models\LedgerEntry;
use App\Models\SyncRun;
use App\Models\User;
use App\Platform\ClientBackups;
use App\Platform\Php;
use App\Platform\PlatformAdmin;
use App\Platform\PlatformAudit;
use App\Platform\PlatformInstaller;
use App\Platform\SubscriptionAlerts;
use App\Platform\Tenant;
use App\Platform\TenantManager;
use App\Platform\TenantStats;
use App\Services\Audit;
use App\Services\BackupService;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\Settings;
use App\Sync\CompanySync;
use App\Sync\FtthApiClient;
use App\WhatsApp\Gateway;
use App\WhatsApp\Inbox;
use App\WhatsApp\Notifier;
use App\WhatsApp\Outbox;
use App\WhatsApp\WahaGateway;
use Carbon\CarbonImmutable;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

// Accounts that show advance credit beside an unpaid debt (from before advances paid debts): lists
// them; with --apply the credit pays those debts through the normal ledger posting (nothing edited).
Artisan::command('credits:settle {--apply : Use the credit to pay the debts}', function (PaymentService $payments) {
    $accounts = Account::whereHas('debts', fn ($q) => $q->whereIn('status', ['open', 'partial'])->where('balance', '>', 0))->with('subscriber')->get()
        ->filter(fn ($account) => $payments->creditBalance($account) > 0)->values();
    if ($accounts->isEmpty()) {
        $this->info('No account has credit beside a debt.');

        return 0;
    }
    $total = 0;
    foreach ($accounts as $account) {
        $credit = $payments->creditBalance($account);
        $due = (int) $account->debts()->whereIn('status', ['open', 'partial'])->sum('balance');
        $used = $this->option('apply') ? app(Audit::class)->withSource('system', fn () => $payments->settleFromCredit($account)) : min($credit, $due);
        $total += $used;
        $this->line(sprintf('%s (%s): credit %s, debts %s → %s %s', $account->subscriber?->full_name, $account->username, number_format($credit), number_format($due), $this->option('apply') ? 'paid' : 'would pay', number_format($used)));
    }
    $this->info(($this->option('apply') ? 'Paid from credit: ' : 'Would pay from credit (run with --apply): ').number_format($total).' IQD on '.$accounts->count().' accounts.');

    return 0;
})->purpose('List (or settle with --apply) accounts that have advance credit beside an unpaid debt');

// How many accounts hold advance credit («رصيد مقدم») and how much: a reading only, nothing changes.
Artisan::command('credits:list {--all : Print every account, not only the first 50}', function (Ledger $ledger) {
    $rows = LedgerEntry::where('ledger_account_id', $ledger->accountId(Ledger::CUSTOMER_CREDIT))
        ->whereNotNull('account_id')->groupBy('account_id')
        ->selectRaw('account_id, sum(credit - debit) as credit')->havingRaw('sum(credit - debit) > 0')
        ->orderByDesc('credit')->get();
    if ($rows->isEmpty()) {
        $this->info('No account has advance credit.');

        return 0;
    }
    $accounts = Account::with('subscriber:id,full_name')->whereIn('id', $rows->pluck('account_id'))->get()->keyBy('id');
    foreach ($this->option('all') ? $rows : $rows->take(50) as $row) {
        $account = $accounts->get($row->account_id);
        $debt = (int) Debt::where('account_id', $row->account_id)->whereIn('status', ['open', 'partial'])->sum('balance');
        $this->line(sprintf('%s (%s): credit %s%s', $account?->subscriber?->full_name ?? '?', $account?->username ?? $row->account_id, number_format((int) $row->credit), $debt > 0 ? ', unpaid debts '.number_format($debt) : ''));
    }
    if (! $this->option('all') && $rows->count() > 50) {
        $this->line('… and '.($rows->count() - 50).' more (run with --all).');
    }
    $this->info('Accounts with advance credit: '.$rows->count().' · total '.number_format((int) $rows->sum('credit')).' IQD.');

    return 0;
})->purpose('Count and list the accounts that hold advance credit (read only)');

// Sets up the platform (SaaS) database and registers this system as client 1; safe on every start.
Artisan::command('platform:install', function (PlatformInstaller $installer) {
    $result = $installer->install();
    $this->info(($result['created_database'] ? 'Platform database created. ' : '')."Client 1: {$result['tenant']->name} ({$result['tenant']->database}).");
})->purpose('Set up the platform database and register the original system as client 1');

// Runs a command once per client, each in its own process on its own database (TENANT=slug), side
// by side so one client's pauses never hold up another. Before the platform exists: just client 1.
Artisan::command('tenants:each {task : The command and its options, quoted}', function (TenantManager $tenants) {
    $task = preg_split('/\s+/', trim($this->argument('task')));
    // Before the platform database exists there is only client 1: one run, no TENANT.
    $slugs = $tenants->runnableSlugs() ?? [null];
    $results = Process::pool(function (Pool $pool) use ($slugs, $task) {
        foreach ($slugs as $slug) {
            $pool->as($slug ?? 'main')->path(base_path())->env($slug === null ? [] : ['TENANT' => $slug])->forever()
                ->command([Php::binary(), 'artisan', ...$task, '--no-interaction']);
        }
    })->start()->wait();
    $failed = 0;
    foreach ($results as $slug => $result) {
        $output = trim($result->output()."\n".$result->errorOutput());
        if ($output !== '') {
            $this->line("[{$slug}] ".$output);
        }
        $failed += $result->failed() ? 1 : 0;
    }

    return $failed === 0 ? 0 : 1;
})->purpose('Run a command for every client, each on its own database');

// Builds a NEW client's database (run by TenantProvisioner with TENANT=slug): its tables, the starting
// reference data and its manager account. Refuses client 1 and any database that already has users.
Artisan::command('platform:provision', function (TenantManager $tenants) {
    $tenant = $tenants->current();
    if ($tenant === null || $tenants->isHome()) {
        $this->error('Refused: platform:provision only builds a new client (TENANT=slug), never client 1.');

        return 1;
    }
    if (Schema::hasTable('users') && DB::table('users')->exists()) {
        $this->error("Refused: {$tenant->database} already has users.");

        return 1;
    }
    $this->call('migrate', ['--force' => true]);
    $this->callSilently('db:seed', ['--force' => true]);

    // The seeded first admin becomes the client's manager, with the owner's chosen details.
    $username = (string) getenv('PROVISION_USERNAME');
    $password = (string) getenv('PROVISION_PASSWORD');
    if ($username === '' || strlen($password) < 8) {
        $this->error('Missing manager username or password.');

        return 1;
    }
    $admin = User::role('admin')->orderBy('id')->firstOrFail();
    $admin->forceFill([
        'name' => (string) getenv('PROVISION_NAME') ?: 'المدير',
        'username' => $username,
        'password' => $password,
        'phone' => ((string) getenv('PROVISION_PHONE')) ?: null,
        'password_changed_at' => null,
    ])->save();
    app(Settings::class)->set('company.name', (string) getenv('PROVISION_COMPANY'));
    $this->info("Client {$tenant->slug} ready on {$tenant->database}.");
})->purpose('Build a new client database (internal)');

// The platform owner's account, from the server's command line only (the password is typed, hidden).
Artisan::command('platform:admin {username} {--name= : Display name}', function (string $username) {
    $password = (string) $this->secret('كلمة المرور (12 حرفاً على الأقل)');
    if (strlen($password) < 12 || $password !== (string) $this->secret('أعد كتابة كلمة المرور')) {
        $this->error('Passwords do not match or are shorter than 12 characters.');

        return 1;
    }
    $admin = PlatformAdmin::whereRaw('lower(username) = ?', [strtolower($username)])->first() ?? new PlatformAdmin(['username' => $username]);
    $admin->fill(['name' => $this->option('name') ?: ($admin->name ?: $username), 'password' => $password, 'is_active' => true])->save();
    app(PlatformAudit::class)->log($admin->wasRecentlyCreated ? 'platform_admin.created' : 'platform_admin.password_reset', null, null, ['username' => $admin->username]);
    $this->info("Platform owner «{$admin->username}» saved.");
})->purpose('Create the platform owner account or reset its password');

// The subscribers and staff counts shown on the platform panel.
Artisan::command('platform:stats', function (TenantStats $stats) {
    $this->info('Clients refreshed: '.$stats->refreshAll());
})->purpose('Refresh the per-client counts on the platform panel');
Schedule::command('platform:stats')->hourly()->withoutOverlapping(30);

// «تنبيه قرب انتهاء الاشتراك» (7, 3, 1 days and the day itself), each raised once.
Artisan::command('platform:alerts', function (SubscriptionAlerts $alerts) {
    $this->info('Alerts raised: '.$alerts->scan());
})->purpose('Raise the subscription alerts that are due');
Schedule::command('platform:alerts')->hourly()->withoutOverlapping(30);

// The daily copy of the platform database and of every client's database but client 1's.
Artisan::command('platform:backup', function (ClientBackups $backups) {
    $failed = 0;
    foreach ($backups->run() as $database => $result) {
        $ok = ! str_starts_with($result, 'failed:');
        $failed += $ok ? 0 : 1;
        $ok ? $this->info("{$database}: {$result}") : $this->error("{$database}: {$result}");
    }

    return $failed === 0 ? 0 : 1;
})->purpose('Back up the platform database and the clients\' databases');
Schedule::command('platform:backup')->dailyAt('03:30')->timezone('Asia/Baghdad')->withoutOverlapping(120);

// A client's own WhatsApp (QR) service, for deploy/client-whatsapp.sh: makes its keys once (kept
// encrypted in the platform database) and prints them as the service's environment lines.
Artisan::command('platform:whatsapp {slug} {--url= : The service inside the server, e.g. http://waha-slug:3000}', function (string $slug) {
    $tenant = Tenant::where('slug', $slug)->first();
    if ($tenant === null || $tenant->database === app(TenantManager::class)->homeDatabase()) {
        $this->error("No such client (client 1 keeps the server's own WhatsApp): {$slug}");

        return 1;
    }
    $url = (string) ($this->option('url') ?: "http://waha-{$slug}:3000");
    if (! preg_match('#^http://waha-[a-z0-9-]+:3000$#', $url)) {
        $this->error("Unexpected service address: {$url}");

        return 1;
    }
    $new = ! $tenant->hasWhatsapp();
    $tenant->forceFill([
        'waha_url' => $url,
        'waha_api_key' => $tenant->waha_api_key ?: bin2hex(random_bytes(32)),
        'waha_webhook_secret' => $tenant->waha_webhook_secret ?: bin2hex(random_bytes(32)),
    ])->save();
    if ($new) {
        app(PlatformAudit::class)->log('tenant.whatsapp_service', $tenant, null, ['waha_url' => $url]);
    }
    $this->line('WAHA_API_KEY='.$tenant->waha_api_key);
})->purpose('Create (once) and print the keys of a client\'s own WhatsApp service');

// Which client this process works on (for checks and for `tenants:each`).
Artisan::command('tenants:current', function (TenantManager $tenants) {
    $this->line(($tenants->current()?->slug ?? 'main').' '.config('database.connections.'.config('database.default').'.database'));
})->purpose('Show the client and database this process works on');

Artisan::command('backup:run', function (BackupService $backups) {
    $run = $backups->run('cli');
    $run->status === 'success'
        ? $this->info("Backup uploaded: {$run->file_name}")
        : $this->error("Backup failed: {$run->error}");

    return $run->status === 'success' ? 0 : 1;
})->purpose('Dump the database and upload it to Google Drive');

// On servers that run the scheduler (the Docker Compose setup). Free hosts use the GitHub Actions trigger instead.
Schedule::command('tenants:each', ['backup:run'])->dailyAt('03:00')->timezone('Asia/Baghdad')->withoutOverlapping();

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

Schedule::command('tenants:each', ['company:sync --scheduled'])->everyFiveMinutes()->withoutOverlapping(30);

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
Schedule::command('tenants:each', ['company:keep-session'])->everyTenMinutes()->withoutOverlapping(5);

Artisan::command('whatsapp:scan', function (Notifier $notifier, Inbox $inbox) {
    $inbox->closeStuck();
    $alerts = $notifier->scanSecondaryExpiring();
    $messages = $notifier->scanSubscribers();
    $this->info("Queued {$alerts} staff alerts and {$messages} subscriber messages.");
})->purpose('Queue the WhatsApp alerts for staff and the automatic messages for subscribers');

Artisan::command('whatsapp:dispatch', function (Outbox $outbox, Gateway $gateway, Settings $settings, Notifier $notifier) {
    // Note reminders are queued here, every minute, so they go out at the time chosen.
    $notifier->noteReminders();
    $notifier->activationReminders();
    $separate = $outbox->separateLines();
    // With the QR connection, nothing is tried while the phone is not linked: the queue waits.
    if ($settings->get('whatsapp.driver') === 'meta' || ($gateway instanceof WahaGateway && $gateway->isConnected())) {
        [$sent, $failed] = $outbox->dispatch($gateway, line: $separate ? 'main' : 'all');
        if ($sent + $failed > 0) {
            $this->info("Sent {$sent}, failed {$failed}.");
        }
    }
    // The subscribers' messages wait for the second phone; they never go out from the staff's number.
    if ($separate && ($notify = WahaGateway::line('notify'))->isConnected()) {
        [$sent, $failed] = $outbox->dispatch($notify, line: 'notify');
        if ($sent + $failed > 0) {
            $this->info("Notifications line: sent {$sent}, failed {$failed}.");
        }
    }

    return 0;
})->purpose('Send queued WhatsApp messages slowly from the linked phone');

Schedule::command('tenants:each', ['whatsapp:scan'])->everyFifteenMinutes()->withoutOverlapping(10);
// In the background so the pauses between messages never hold up the other scheduled tasks.
Schedule::command('tenants:each', ['whatsapp:dispatch'])->everyMinute()->withoutOverlapping(10)->runInBackground();
