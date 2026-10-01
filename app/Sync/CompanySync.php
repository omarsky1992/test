<?php

namespace App\Sync;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Subscriber;
use App\Models\SyncRun;
use App\Services\ActivationService;
use App\Services\Audit;
use App\Services\RenewalService;
use App\Support\Arabic;
use App\Support\CompanyData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brings the subscriber list up to date with the company site.
 *
 * - New subscribers and accounts are created (matched by the company's customer and subscription IDs,
 *   then by username, so nobody is created twice).
 * - The company's current data (plan, status, end date, device, FAT, port, zone, GPS) replaces the
 *   local copy. Name and phone are only filled when empty, so manual edits are kept.
 * - Days left going from 0 to more than 0 is a renewal: one secondary debt per renewal.
 * - Financial history is never touched.
 */
class CompanySync
{
    private const MAX_ERRORS = 100;

    /** @var array<string, Account> */
    private array $bySubscription = [];

    /** @var array<string, Account> */
    private array $byUsername = [];

    /** @var array<string, Subscriber> */
    private array $subscribers = [];

    /** @var array<string, int> */
    private array $plans = [];

    private array $stats = [];

    private array $errors = [];

    public function __construct(
        private CompanyClient $client,
        private RenewalService $renewals,
        private ActivationService $activations,
        private Audit $audit,
    ) {
    }

    public function run(string $trigger = 'manual', ?CompanyClient $client = null): SyncRun
    {
        $run = SyncRun::create(['trigger' => $trigger, 'status' => 'running', 'started_at' => now(), 'created_by' => Auth::id()]);
        $this->stats = array_fill_keys(['received', 'subscribers_created', 'subscribers_updated', 'accounts_created', 'accounts_updated', 'unchanged', 'renewals', 'debts_created', 'renewals_without_price', 'skipped'], 0);
        $this->errors = [];
        $this->preload();
        $now = CarbonImmutable::now();

        try {
            $records = ($client ?? $this->client)->records(['needs_details' => fn (array $r) => $this->needsDetails($r)]);
            foreach ($records as $record) {
                $this->stats['received']++;
                try {
                    DB::transaction(fn () => $this->apply($record, $run, $now));
                } catch (Throwable $e) {
                    $this->stats['skipped']++;
                    $this->error($record, $e->getMessage());
                }
            }
            $run->update(['status' => 'success', 'finished_at' => now(), 'stats' => [...$this->stats, 'errors' => $this->errors]]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage(), 'stats' => [...$this->stats, 'errors' => $this->errors]]);
        }

        $this->audit->log('company.synced', $run, null, [...$this->stats, 'status' => $run->status, 'errors' => count($this->errors)], $run->error, source: 'sync');

        return $run->fresh();
    }

    /**
     * Fetches a few records and shows how they would be read, without saving anything.
     *
     * @return array<int, array<string, ?string>>
     */
    public function preview(int $limit = 10): array
    {
        $rows = [];
        foreach ($this->client->records(['limit' => $limit, 'needs_details' => fn () => true, 'detail_limit' => $limit]) as $record) {
            $rows[] = $record;
        }

        return $rows;
    }

    /**
     * The customers whose details (phone, GPS, ONT, FAT, port) should be fetched on this run:
     * new accounts or accounts missing them. A customer checked in the last week is skipped,
     * so ones the site has no GPS for are not asked for again and again.
     *
     * @return array<int, string>
     */
    public function customersNeedingDetails(CompanyClient $client, int $limit): array
    {
        $this->preload();
        $ids = [];
        foreach ($client->records() as $record) {
            $id = $record['customer_id'];
            if ($id === null || isset($ids[$id]) || Cache::has("sync.details_checked.{$id}") || ! $this->needsDetails($record)) {
                continue;
            }
            $ids[$id] = true;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * @param  array<int, string>  $customerIds
     */
    public static function markDetailsChecked(array $customerIds): void
    {
        foreach ($customerIds as $id) {
            Cache::put("sync.details_checked.{$id}", true, now()->addDays(7));
        }
    }

    private function apply(array $record, SyncRun $run, CarbonImmutable $now): void
    {
        $username = $record['username'];
        $subscriptionId = $record['subscription_id'];
        if ($username === null && $subscriptionId === null) {
            throw new \RuntimeException('سجل بدون اسم جهاز ولا معرف اشتراك.');
        }

        $account = ($subscriptionId !== null ? ($this->bySubscription[$subscriptionId] ?? null) : null)
            ?? ($username !== null ? ($this->byUsername[mb_strtolower($username)] ?? null) : null);

        $subscriber = $account?->subscriber
            ?? ($record['customer_id'] !== null ? ($this->subscribers[$record['customer_id']] ?? null) : null)
            ?? $this->createSubscriber($record);

        $this->updateSubscriber($subscriber, $record);

        if ($account === null) {
            if ($username === null) {
                throw new \RuntimeException('اشتراك جديد بدون اسم جهاز.');
            }
            $this->createAccount($subscriber, $record, $now);

            return;
        }

        $this->updateAccount($account, $record, $run, $now);
    }

    private function createSubscriber(array $record): Subscriber
    {
        if ($record['customer_id'] === null) {
            throw new \RuntimeException('مشترك جديد بدون معرف مشترك.');
        }
        $name = $record['name'] ?? ('بدون اسم – '.($record['username'] ?? $record['customer_id']));
        $phone = CompanyData::phone($record['phone']);

        $subscriber = Subscriber::create([
            'branch_id' => Branch::query()->orderBy('id')->value('id'),
            'code' => 'TMP-'.bin2hex(random_bytes(8)),
            'full_name' => mb_substr($name, 0, 150),
            'name_search' => Arabic::normalize($name),
            'phone' => $phone,
            'phone_normalized' => $phone !== null ? Arabic::phone($phone) : null,
            'external_id' => $record['customer_id'],
            'status' => 'active',
        ]);
        $subscriber->update(['code' => 'C-'.str_pad((string) $subscriber->id, 6, '0', STR_PAD_LEFT)]);
        $this->subscribers[$record['customer_id']] = $subscriber;
        $this->stats['subscribers_created']++;
        $this->audit->log('subscriber.synced_new', $subscriber, null, ['code' => $subscriber->code, 'full_name' => $subscriber->full_name, 'phone' => $phone, 'external_id' => $record['customer_id']], 'مزامنة من موقع الشركة', $subscriber->id, 'sync');

        return $subscriber;
    }

    private function updateSubscriber(Subscriber $subscriber, array $record): void
    {
        $changes = [];
        $phone = CompanyData::phone($record['phone']);
        if (blank($subscriber->phone) && $phone !== null) {
            $changes['phone'] = $phone;
            $changes['phone_normalized'] = Arabic::phone($phone);
        }
        if ($subscriber->external_id === null && $record['customer_id'] !== null && ! isset($this->subscribers[$record['customer_id']])) {
            $changes['external_id'] = $record['customer_id'];
            $this->subscribers[$record['customer_id']] = $subscriber;
        }
        if ($changes === []) {
            return;
        }
        $original = $subscriber->getAttributes();
        $subscriber->update($changes);
        $this->stats['subscribers_updated']++;
        $this->audit->log('subscriber.synced', $subscriber, array_intersect_key($original, $changes), $changes, 'مزامنة من موقع الشركة', $subscriber->id, 'sync');
    }

    private function createAccount(Subscriber $subscriber, array $record, CarbonImmutable $now): void
    {
        $endsAt = CompanyData::date($record['ends_at']);
        $status = CompanyData::status($record['status']);

        $account = Account::create([
            'subscriber_id' => $subscriber->id,
            'branch_id' => $subscriber->branch_id,
            'username' => mb_substr($record['username'], 0, 80),
            ...$this->companyFields($record, $endsAt, $status, $now),
            'service_ends_at' => $endsAt,
            'status' => 'active',
        ]);
        $account->setRelation('subscriber', $subscriber);
        $this->index($account);
        $this->stats['accounts_created']++;
        // The first sighting is the starting point, never a renewal.
        $this->audit->log('account.synced_new', $account, null, $this->auditable($account->getAttributes()), 'مزامنة من موقع الشركة', $subscriber->id, 'sync');
    }

    private function updateAccount(Account $account, array $record, SyncRun $run, CarbonImmutable $now): void
    {
        $endsAt = CompanyData::date($record['ends_at']);
        $status = CompanyData::status($record['status']);
        $previousDays = $account->company_days_left;
        $previousEndsAt = $account->external_ends_at;

        $fields = $this->companyFields($record, $endsAt, $status, $now);
        // Days left fall by one every day; only real changes are counted and logged.
        $tracked = array_diff_key($fields, array_flip(['company_synced_at', 'external_synced_at', 'company_days_left']));
        $changed = array_filter($tracked, function ($value, $key) use ($account) {
            $current = $account->{$key};
            if ($value instanceof CarbonImmutable || $current instanceof CarbonImmutable) {
                return ! ($value instanceof CarbonImmutable && $current instanceof CarbonImmutable && $value->equalTo($current));
            }

            return (string) $current !== (string) $value;
        }, ARRAY_FILTER_USE_BOTH);

        $original = $account->getAttributes();
        $account->update($fields);

        if ($changed === []) {
            $this->stats['unchanged']++;
        } else {
            $this->stats['accounts_updated']++;
            $this->audit->log('account.synced', $account, $this->auditable(array_intersect_key($original, $changed)), $this->auditable($changed), 'مزامنة من موقع الشركة', $account->subscriber_id, 'sync');
            if (array_key_exists('external_ends_at', $changed)) {
                $this->activations->refreshAccountEnd($account);
            }
        }

        $newDays = $fields['company_days_left'];
        if ($previousDays === 0 && $newDays !== null && $newDays > 0) {
            $renewal = $this->renewals->record($account, $previousDays, $newDays, $previousEndsAt, $endsAt, $record['plan'], $run, $now);
            if ($renewal->wasRecentlyCreated) {
                $this->stats['renewals']++;
                $renewal->debt_id !== null ? $this->stats['debts_created']++ : $this->stats['renewals_without_price']++;
            }
        }
    }

    /**
     * The company's view of a subscription. Device fields keep their local value when the site sends nothing.
     */
    private function companyFields(array $record, ?CarbonImmutable $endsAt, ?string $status, CarbonImmutable $now): array
    {
        $days = Account::daysLeft($endsAt, $now);
        if ($days !== null && CompanyData::isExpiredStatus($status)) {
            $days = 0;
        }

        return array_filter([
            'external_subscription_id' => $record['subscription_id'],
            'external_plan' => $record['plan'] !== null ? mb_substr($record['plan'], 0, 60) : null,
            'current_plan_id' => $record['plan'] !== null ? ($this->plans[CompanyData::planKey($record['plan'])] ?? null) : null,
            'external_status' => $status,
            'external_ends_at' => $endsAt,
            'company_days_left' => $days,
            'zone_code' => $record['zone'] !== null ? mb_substr($record['zone'], 0, 50) : null,
            'gps' => $record['gps'] !== null ? mb_substr($record['gps'], 0, 60) : null,
            'serial_number' => $record['serial'] !== null ? mb_strtoupper(mb_substr($record['serial'], 0, 60)) : null,
            'fat_code' => $record['fat'] !== null ? mb_substr($record['fat'], 0, 50) : null,
            'port_number' => $record['port'] !== null ? mb_substr($record['port'], 0, 20) : null,
            'company_synced_at' => $now,
            'external_synced_at' => $now,
        ], fn ($v) => $v !== null);
    }

    private function needsDetails(array $record): bool
    {
        $account = ($record['subscription_id'] !== null ? ($this->bySubscription[$record['subscription_id']] ?? null) : null)
            ?? ($record['username'] !== null ? ($this->byUsername[mb_strtolower($record['username'])] ?? null) : null);

        return $account === null || blank($account->serial_number) || blank($account->gps) || blank($account->fat_code)
            || blank($account->subscriber?->phone);
    }

    private function preload(): void
    {
        $this->bySubscription = $this->byUsername = $this->subscribers = [];
        foreach (Account::with('subscriber')->get() as $account) {
            $this->index($account);
        }
        foreach (Subscriber::whereNotNull('external_id')->get() as $subscriber) {
            $this->subscribers[$subscriber->external_id] = $subscriber;
        }
        $this->plans = CompanyData::planIndex();
    }

    private function index(Account $account): void
    {
        $this->byUsername[mb_strtolower($account->username)] = $account;
        if ($account->external_subscription_id !== null) {
            $this->bySubscription[$account->external_subscription_id] = $account;
        }
    }

    private function auditable(array $values): array
    {
        unset($values['secret_encrypted'], $values['updated_at'], $values['created_at']);

        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i') : $v, $values);
    }

    private function error(array $record, string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = ['username' => $record['username'] ?? null, 'customer_id' => $record['customer_id'] ?? null, 'message' => mb_substr($message, 0, 300)];
        }
    }
}
