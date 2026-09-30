<?php

namespace App\Imports;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ImportRun;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Services\ActivationService;
use App\Services\Audit;
use App\Services\SubscriberService;
use App\Support\Arabic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports subscribers and their accounts from a spreadsheet: maps columns to fields, matches
 * existing records (company customer number, then phone, then username), shows a preview, and
 * only then writes. It never deletes, and it changes existing data only in the mode the user picks.
 */
class SubscriberImporter
{
    public const MODE_SKIP = 'skip';         // existing records are left untouched
    public const MODE_FILL = 'fill';         // only empty fields of existing records are filled
    public const MODE_OVERWRITE = 'overwrite'; // non-empty file values replace existing ones

    /** field => [Arabic label, header synonyms] */
    public const FIELDS = [
        'external_id' => ['رقم المشترك (معرف الشركة)', ['معرف المشترك', 'رقم المشترك', 'customer id', 'customerid', 'customer no', 'id']],
        'full_name' => ['الاسم', ['الاسم', 'اسم المشترك', 'الاسم الكامل', 'name', 'full name', 'customer name']],
        'phone' => ['رقم الهاتف', ['رقم الهاتف', 'الهاتف', 'الموبايل', 'رقم الموبايل', 'phone', 'mobile', 'phone number']],
        'alt_phone' => ['هاتف آخر', ['هاتف آخر', 'هاتف اخر', 'هاتف 2', 'alt phone', 'phone 2']],
        'address' => ['العنوان', ['العنوان', 'address']],
        'username' => ['اليوزر / اسم الجهاز', ['اليوزر', 'يوزر', 'اسم المستخدم', 'username', 'user', 'اسم الجهاز', 'device name']],
        'secret' => ['الباسورد', ['الباسورد', 'كلمة المرور', 'كلمة السر', 'password']],
        'serial_number' => ['السيريال (ONT)', ['تسلسل ont', 'السيريال', 'سيريال', 'serial', 'ont serial', 'ont', 'serial number']],
        'fat_code' => ['الفات', ['الفات', 'fat']],
        'pole_number' => ['رقم العامود', ['رقم العامود', 'العامود', 'pole', 'pole number']],
        'zone_code' => ['المنطقة / FDT', ['المنطقة', 'fdt', 'zone']],
        'gps' => ['الموقع GPS', ['gps', 'الموقع', 'location', 'الاحداثيات']],
        'location_label' => ['البيت', ['البيت', 'وصف الموقع', 'house']],
        'plan' => ['الفئة / الاشتراك', ['اسم الاشتراك', 'الفئة', 'الاشتراك', 'الباقة', 'plan', 'package', 'subscription']],
        'external_status' => ['حالة الاشتراك', ['الحالة', 'حالة الاشتراك', 'status']],
        'external_ends_at' => ['تاريخ الانتهاء', ['تاريخ الانتهاء', 'تاريخ انتهاء الصلاحية', 'الانتهاء', 'expiry', 'expiry date', 'expires', 'end date']],
        'external_subscription_id' => ['معرف الاشتراك', ['معرف الاشتراك', 'رقم الاشتراك', 'subscription id']],
        'notes' => ['ملاحظات', ['ملاحظات', 'notes']],
    ];

    private const SUBSCRIBER_FIELDS = ['full_name', 'phone', 'alt_phone', 'address', 'external_id'];
    private const ACCOUNT_FIELDS = ['secret', 'serial_number', 'fat_code', 'pole_number', 'zone_code', 'gps', 'location_label'];
    private const COMPANY_FIELDS = ['external_plan', 'external_status', 'external_ends_at', 'external_subscription_id', 'current_plan_id'];

    /** @var array<int, array<string, mixed>> */
    private array $auditBuffer = [];

    private ?int $importRunId = null;

    public function __construct(
        private SpreadsheetReader $reader,
        private SubscriberService $subscribers,
        private ActivationService $activations,
        private Audit $audit,
    ) {
    }

    /**
     * Guesses which column feeds which field from the header names.
     *
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    public function suggestMapping(array $headers): array
    {
        $normalized = array_map(fn ($h) => $this->key($h), $headers);
        $mapping = [];
        $used = [];
        foreach (self::FIELDS as $field => [, $synonyms]) {
            $mapping[$field] = null;
            foreach ($synonyms as $synonym) {
                $index = array_search($this->key($synonym), $normalized, true);
                if ($index !== false && ! in_array($index, $used, true)) {
                    $mapping[$field] = $index;
                    $used[] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Builds the preview: what would be created, updated, skipped, and which rows have problems.
     *
     * @param  array<string, int|null>  $mapping
     * @param  array{mode?: string, update_company_data?: bool, date_format?: string}  $options
     */
    public function analyze(string $path, string $originalName, array $mapping, array $options = []): ImportPlan
    {
        $sheet = $this->reader->read($path, $originalName);

        return $this->plan($sheet['rows'], $mapping, $options);
    }

    /**
     * Runs the import in one transaction (all or nothing) from a fresh analysis, so the result
     * matches the data as it is now, not as it was when the preview was shown.
     */
    public function execute(string $path, string $originalName, array $mapping, array $options = []): ImportRun
    {
        $plan = $this->analyze($path, $originalName, $mapping, $options);
        $run = ImportRun::create([
            'kind' => 'subscribers',
            'file_name' => mb_substr($originalName, 0, 200),
            'mode' => $plan->mode,
            'mapping' => $mapping,
            'status' => 'running',
            'created_by' => Auth::id(),
        ]);

        try {
            @set_time_limit(900);
            DB::transaction(function () use ($plan, $run) {
                $this->importRunId = $run->id;
                foreach ($plan->groups as $group) {
                    if ($group['error'] === null) {
                        $this->applyGroup($group, $plan);
                    }
                }
                // New subscribers were inserted with a temporary code; give them their final C-000123 code in one statement.
                DB::statement("UPDATE subscribers SET code = 'C-' || lpad(id::text, 6, '0') WHERE code LIKE 'TMP-%'");
                foreach (array_chunk($this->auditBuffer, 500) as $chunk) {
                    AuditLog::insert($chunk);
                }
                $this->auditBuffer = [];
            });
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => mb_strimwidth($e->getMessage(), 0, 1000, '…'), 'stats' => $plan->stats, 'finished_at' => now()]);
            $this->audit->log('subscribers.import_failed', $run, null, ['file' => $originalName, 'error' => $run->error], source: 'sync');
            throw $e;
        }

        $run->update(['status' => 'success', 'stats' => $plan->stats, 'finished_at' => now()]);
        $this->audit->log('subscribers.imported', $run, null, [
            'file' => $originalName,
            'mode' => $plan->mode,
            'update_company_data' => $plan->updateCompanyData,
            ...$plan->stats,
        ], source: 'sync');

        return $run;
    }

    /**
     * @param  array<int, array{line: int, cells: array<int, string>}>  $rows
     */
    public function plan(array $rows, array $mapping, array $options = []): ImportPlan
    {
        $mode = in_array($options['mode'] ?? null, [self::MODE_SKIP, self::MODE_FILL, self::MODE_OVERWRITE], true) ? $options['mode'] : self::MODE_SKIP;
        $plan = new ImportPlan($mode, (bool) ($options['update_company_data'] ?? false));
        $dateFormat = $this->detectDateFormat($rows, $mapping['external_ends_at'] ?? null, $options['date_format'] ?? 'auto');
        $plans = $this->planIndex();

        // 1) Read and validate every row.
        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->parseRow($row, $mapping, $dateFormat, $plans);
        }

        // 2) Group rows of the same person, and rows of the same line (older subscriptions of one device).
        $groups = [];
        foreach ($records as $record) {
            if ($record['error'] !== null) {
                $plan->addRow($record['line'], $record['values'], 'error', [$record['error'], ...$record['warnings']]);

                continue;
            }
            $key = $record['values']['external_id'] !== '' ? 'ext:'.$record['values']['external_id'] : 'phone:'.$record['phone_normalized'];
            $groups[$key] ??= ['key' => $key, 'rows' => [], 'accounts' => []];
            $groups[$key]['rows'][] = $record;
        }

        // 3) Match each person and each line against the database.
        $existing = $this->preload($groups);
        foreach ($groups as $key => $group) {
            $plan->groups[] = $this->matchGroup($group, $existing, $plan);
        }

        return $plan;
    }

    private function parseRow(array $row, array $mapping, string $dateFormat, array $plans): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $index = $mapping[$field] ?? null;
            $value = $index !== null && $index !== '' ? trim((string) ($row['cells'][(int) $index] ?? '')) : '';
            $values[$field] = in_array($value, ['-', '—', 'null', 'NULL', 'N/A'], true) ? '' : $value;
        }

        $warnings = [];
        $phoneNormalized = $values['phone'] !== '' ? Arabic::phone($values['phone']) : '';
        if ($values['phone'] !== '' && ! preg_match('/^9647\d{9}$/', $phoneNormalized)) {
            $warnings[] = "رقم الهاتف «{$values['phone']}» ليس رقماً عراقياً صحيحاً (يُحفظ كما هو)";
        }
        if ($phoneNormalized !== '' && preg_match('/^9647\d{9}$/', $phoneNormalized)) {
            $values['phone'] = '0'.substr($phoneNormalized, 3);
        }
        $values['serial_number'] = strtoupper($values['serial_number']);

        $planId = null;
        if ($values['plan'] !== '') {
            $planId = $plans[$this->planKey($values['plan'])] ?? null;
            if ($planId === null) {
                $warnings[] = "الفئة «{$values['plan']}» غير معروفة في النظام (تُحفظ كمعلومة فقط)";
            }
        }

        $endsAt = null;
        if ($values['external_ends_at'] !== '') {
            $endsAt = $this->parseDate($values['external_ends_at'], $dateFormat);
            if ($endsAt === null) {
                $warnings[] = "تاريخ الانتهاء «{$values['external_ends_at']}» غير مفهوم (يُتجاهل)";
            }
        }

        $status = strtolower($values['external_status']);
        $status = match (true) {
            $status === '' => null,
            in_array($status, ['active', 'فعال', 'فعّال', 'نشط'], true) => 'active',
            in_array($status, ['expired', 'منتهي', 'منتهية', 'منتهية الصلاحية'], true) => 'expired',
            default => mb_substr($status, 0, 30),
        };

        $error = match (true) {
            $values['external_id'] === '' && $phoneNormalized === '' => 'لا يوجد رقم مشترك ولا رقم هاتف للتعرّف على المشترك',
            $values['full_name'] === '' => 'الاسم مفقود',
            mb_strlen($values['username']) > 80 => 'اليوزر أطول من 80 حرفاً',
            default => null,
        };
        if ($values['username'] === '' && $error === null) {
            $warnings[] = 'بدون يوزر: يُضاف المشترك بدون حساب';
        }

        return [
            'line' => $row['line'],
            'values' => $values,
            'phone_normalized' => $phoneNormalized,
            'plan_id' => $planId,
            'ends_at' => $endsAt,
            'status' => $status,
            'warnings' => $warnings,
            'error' => $error,
        ];
    }

    private function preload(array $groups): array
    {
        $externalIds = [];
        $phones = [];
        $usernames = [];
        $serials = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $r) {
                if ($r['values']['external_id'] !== '') {
                    $externalIds[] = $r['values']['external_id'];
                }
                if ($r['phone_normalized'] !== '') {
                    $phones[] = $r['phone_normalized'];
                }
                if ($r['values']['username'] !== '') {
                    $usernames[] = mb_strtolower($r['values']['username']);
                }
                if ($r['values']['serial_number'] !== '') {
                    $serials[] = $r['values']['serial_number'];
                }
            }
        }

        $byExternal = [];
        foreach (array_chunk(array_unique($externalIds), 1000) as $chunk) {
            foreach (Subscriber::whereIn('external_id', $chunk)->get() as $s) {
                $byExternal[$s->external_id] = $s;
            }
        }
        $byPhone = [];
        foreach (array_chunk(array_unique($phones), 1000) as $chunk) {
            foreach (Subscriber::whereIn('phone_normalized', $chunk)->get() as $s) {
                $byPhone[$s->phone_normalized][] = $s;
            }
        }
        $accounts = [];
        foreach (array_chunk(array_unique($usernames), 1000) as $chunk) {
            foreach (Account::whereIn(DB::raw('lower(username)'), $chunk)->get() as $a) {
                $accounts[mb_strtolower($a->username)] = $a;
            }
        }
        $serialOwners = [];
        foreach (array_chunk(array_unique($serials), 1000) as $chunk) {
            foreach (Account::whereIn('serial_number', $chunk)->get(['id', 'username', 'serial_number']) as $a) {
                $serialOwners[$a->serial_number][] = mb_strtolower($a->username);
            }
        }

        return compact('byExternal', 'byPhone', 'accounts', 'serialOwners');
    }

    private function matchGroup(array $group, array $existing, ImportPlan $plan): array
    {
        $first = $group['rows'][0];
        $values = $first['values'];
        $result = ['key' => $group['key'], 'subscriber' => null, 'subscriber_action' => 'create', 'subscriber_changes' => [], 'data' => $values, 'phone_normalized' => $first['phone_normalized'], 'accounts' => [], 'error' => null];

        // Find the person: company number first, then phone (only when unambiguous).
        $subscriber = $values['external_id'] !== '' ? ($existing['byExternal'][$values['external_id']] ?? null) : null;
        if ($subscriber === null && $first['phone_normalized'] !== '') {
            $candidates = $existing['byPhone'][$first['phone_normalized']] ?? [];
            if (count($candidates) > 1) {
                $result['error'] = 'رقم الهاتف مسجل لأكثر من مشترك في النظام. أضف عمود رقم المشترك للتمييز.';
            } elseif (count($candidates) === 1) {
                $candidate = $candidates[0];
                if ($candidate->external_id !== null && $values['external_id'] !== '' && $candidate->external_id !== $values['external_id']) {
                    $result['error'] = "رقم الهاتف مسجل للمشترك {$candidate->full_name} برقم شركة مختلف ({$candidate->external_id}).";
                } else {
                    $subscriber = $candidate;
                }
            }
        }

        if ($result['error'] === null && $subscriber !== null) {
            $result['subscriber'] = $subscriber;
            $result['subscriber_changes'] = $this->changes($subscriber, [
                'full_name' => $values['full_name'],
                'phone' => $values['phone'],
                'alt_phone' => $values['alt_phone'],
                'address' => $values['address'],
                'external_id' => $values['external_id'],
            ], $plan->mode);
            $result['subscriber_action'] = $result['subscriber_changes'] ? 'update' : 'same';
        }

        // Lines: rows with the same username are one account; the newest subscription is the current one.
        $byUsername = [];
        foreach ($group['rows'] as $row) {
            $username = mb_strtolower($row['values']['username']);
            if ($username === '') {
                continue;
            }
            $current = $byUsername[$username] ?? null;
            if ($current === null) {
                $byUsername[$username] = $row + ['merged' => []];
            } else {
                $newer = ($row['ends_at']?->getTimestamp() ?? 0) > ($current['ends_at']?->getTimestamp() ?? 0);
                $kept = $newer ? $row + ['merged' => [...$current['merged'], $current['line']]] : $current;
                if (! $newer) {
                    $kept['merged'][] = $row['line'];
                }
                $byUsername[$username] = $kept;
            }
        }

        foreach ($byUsername as $username => $row) {
            $account = $existing['accounts'][$username] ?? null;
            $entry = ['row' => $row, 'account' => $account, 'action' => 'create', 'changes' => [], 'company' => [], 'error' => null, 'warnings' => []];

            if (isset($plan->claimedUsernames[$username])) {
                $entry['error'] = "اليوزر «{$row['values']['username']}» مكرر في الملف لمشترك آخر (الصف {$plan->claimedUsernames[$username]}).";
            } elseif ($account !== null && $subscriber !== null && $account->subscriber_id !== $subscriber->id) {
                $entry['error'] = "اليوزر «{$row['values']['username']}» مسجل لمشترك آخر في النظام.";
            } elseif ($account !== null && $subscriber === null) {
                $entry['error'] = "اليوزر «{$row['values']['username']}» مسجل لمشترك آخر في النظام.";
            }
            $owners = array_diff($existing['serialOwners'][$row['values']['serial_number']] ?? [], [$username]);
            $serial = $row['values']['serial_number'];
            if ($serial !== '' && $owners) {
                $entry['warnings'][] = "السيريال {$serial} مسجل أيضاً على الحساب ".implode('، ', $owners);
            } elseif ($serial !== '' && isset($plan->claimedSerials[$serial]) && $plan->claimedSerials[$serial] !== $username) {
                $entry['warnings'][] = "السيريال {$serial} مكرر في الملف مع الحساب {$plan->claimedSerials[$serial]}";
            }
            if ($serial !== '') {
                $plan->claimedSerials[$serial] ??= $username;
            }

            $company = array_filter([
                'external_plan' => $row['values']['plan'] ?: null,
                'external_status' => $row['status'],
                'external_ends_at' => $row['ends_at'],
                'external_subscription_id' => $row['values']['external_subscription_id'] ?: null,
                'current_plan_id' => $row['plan_id'],
            ], fn ($v) => $v !== null);

            if ($entry['error'] === null && $account !== null) {
                $entry['changes'] = $this->changes($account, array_intersect_key($row['values'], array_flip(self::ACCOUNT_FIELDS)), $plan->mode);
                $entry['company'] = $plan->updateCompanyData ? $this->companyChanges($account, $company) : [];
                $entry['action'] = ($entry['changes'] || $entry['company']) ? 'update' : 'same';
            } elseif ($entry['error'] === null) {
                $entry['company'] = $company;
            }
            if ($entry['error'] === null) {
                $plan->claimedUsernames[$username] = $row['line'];
            }
            $result['accounts'][] = $entry;
        }

        if ($result['error'] !== null) {
            foreach ($group['rows'] as $row) {
                $plan->addRow($row['line'], $row['values'], 'error', [$result['error'], ...$row['warnings']]);
            }

            return $result;
        }

        // Record the per-row outcome for the preview.
        $plan->countSubscriber($result['subscriber_action']);
        $accountByLine = [];
        foreach ($result['accounts'] as $entry) {
            $accountByLine[$entry['row']['line']] = $entry;
            foreach ($entry['row']['merged'] as $mergedLine) {
                $accountByLine[$mergedLine] = ['merged_into' => $entry['row']['line']];
            }
            if ($entry['error'] === null) {
                $plan->countAccount($entry['action']);
            }
        }
        foreach ($group['rows'] as $i => $row) {
            $entry = $accountByLine[$row['line']] ?? null;
            $messages = $row['warnings'];
            if (isset($entry['merged_into'])) {
                $plan->addRow($row['line'], $row['values'], 'merged', ["اشتراك أقدم لنفس الخط، دُمج مع الصف {$entry['merged_into']}", ...$messages]);

                continue;
            }
            if ($entry !== null && $entry['error'] !== null) {
                $plan->addRow($row['line'], $row['values'], 'error', [$entry['error'], ...$messages]);

                continue;
            }
            $messages = [...$messages, ...($entry['warnings'] ?? [])];
            $subscriberPart = $i === 0 ? $result['subscriber_action'] : 'same';
            $accountPart = $entry['action'] ?? 'same';
            $status = in_array('create', [$subscriberPart, $accountPart], true) ? 'create'
                : (in_array('update', [$subscriberPart, $accountPart], true) ? 'update' : 'same');
            if ($i === 0 && $result['subscriber_changes']) {
                $messages[] = 'تحديث المشترك: '.implode('، ', array_map(fn ($f) => self::FIELDS[$f][0] ?? $f, array_keys($result['subscriber_changes'])));
            }
            if ($entry && $entry['changes']) {
                $messages[] = 'تحديث الحساب: '.implode('، ', array_map(fn ($f) => self::FIELDS[$f][0] ?? $f, array_keys($entry['changes'])));
            }
            if ($entry && $entry['action'] === 'update' && $entry['company']) {
                $messages[] = 'تحديث بيانات الشركة (الحالة/الانتهاء/الفئة)';
            }
            $plan->addRow($row['line'], $row['values'], $status, $messages);
        }

        return $result;
    }

    private function applyGroup(array $group, ImportPlan $plan): void
    {
        $data = $group['data'];
        $subscriber = $group['subscriber'];

        if ($subscriber === null) {
            // Bulk path for new records: one insert each, audit rows written together at the end.
            $id = DB::table('subscribers')->insertGetId([
                'branch_id' => Auth::user()?->branch_id ?? DB::table('branches')->value('id'),
                'code' => 'TMP-'.bin2hex(random_bytes(8)),
                'external_id' => $data['external_id'] !== '' ? $data['external_id'] : null,
                'full_name' => $data['full_name'],
                'name_search' => Arabic::normalize($data['full_name']),
                'phone' => $data['phone'] !== '' ? $data['phone'] : '-',
                'phone_normalized' => $group['phone_normalized'],
                'alt_phone' => $data['alt_phone'] ?: null,
                'address' => $data['address'] ?: null,
                'notes' => $data['notes'] ?: null,
                'status' => 'active',
                'created_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $subscriber = (new Subscriber)->forceFill(['id' => $id, 'branch_id' => Auth::user()?->branch_id]);
            $subscriber->exists = true;
            $this->bufferAudit('subscriber.imported', 'Subscriber', $id, $id, ['full_name' => $data['full_name'], 'phone' => $data['phone'], 'external_id' => $data['external_id']]);
        } elseif ($group['subscriber_changes']) {
            $original = $subscriber->getAttributes();
            $changes = $group['subscriber_changes'];
            if (isset($changes['full_name'])) {
                $changes['name_search'] = Arabic::normalize($changes['full_name']);
            }
            if (isset($changes['phone'])) {
                $changes['phone_normalized'] = Arabic::phone($changes['phone']);
            }
            $subscriber->update([...$changes, 'updated_by' => Auth::id()]);
            $this->audit->changes('subscriber.updated', $subscriber, $original, 'استيراد من ملف', $subscriber->id);
        }

        foreach ($group['accounts'] as $entry) {
            if ($entry['error'] !== null) {
                continue;
            }
            $values = $entry['row']['values'];
            $account = $entry['account'];

            if ($account === null) {
                $company = $entry['company'];
                $id = DB::table('accounts')->insertGetId([
                    'subscriber_id' => $subscriber->id,
                    'branch_id' => $subscriber->branch_id ?? Subscriber::whereKey($subscriber->id)->value('branch_id'),
                    'username' => $values['username'],
                    'secret_encrypted' => $values['secret'] !== '' ? Crypt::encryptString($values['secret']) : null,
                    'serial_number' => $values['serial_number'] ?: null,
                    'fat_code' => $values['fat_code'] ?: null,
                    'pole_number' => $values['pole_number'] ?: null,
                    'location_label' => $values['location_label'] ?: null,
                    'zone_code' => $values['zone_code'] ?: null,
                    'gps' => $values['gps'] ?: null,
                    'current_plan_id' => $company['current_plan_id'] ?? null,
                    'external_plan' => $company['external_plan'] ?? null,
                    'external_status' => $company['external_status'] ?? null,
                    'external_subscription_id' => $company['external_subscription_id'] ?? null,
                    'external_ends_at' => $company['external_ends_at'] ?? null,
                    // A new account has no periods yet, so its service end is the company's date.
                    'service_ends_at' => $company['external_ends_at'] ?? null,
                    'external_synced_at' => now(),
                    'status' => 'active',
                    'created_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->bufferAudit('account.imported', 'Account', $id, $subscriber->id, ['username' => $values['username'], 'serial_number' => $values['serial_number']]);

                continue;
            } elseif ($entry['changes'] || $entry['company']) {
                $original = $account->getAttributes();
                $changes = $entry['changes'];
                if (isset($changes['secret'])) {
                    $changes['secret_encrypted'] = Crypt::encryptString($changes['secret']);
                    unset($changes['secret']);
                }
                $account->update([...$changes, ...$entry['company'], ...($entry['company'] ? ['external_synced_at' => now()] : []), 'updated_by' => Auth::id()]);
                $this->audit->changes('account.updated', $account, $original, 'استيراد من ملف', $account->subscriber_id);
            }

            if (isset($entry['company']['external_ends_at'])) {
                $this->activations->refreshAccountEnd($account);
            }
        }
    }

    private function bufferAudit(string $action, string $type, int $id, int $subscriberId, array $values): void
    {
        $this->auditBuffer[] = [
            'occurred_at' => now(),
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'subscriber_id' => $subscriberId,
            'new_values' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'reason' => "استيراد من ملف (عملية #{$this->importRunId})",
            'source' => 'sync',
        ];
    }

    /**
     * Which fields would change on an existing record in the chosen mode. Empty file values never
     * clear existing data.
     */
    private function changes(object $model, array $incoming, string $mode): array
    {
        if ($mode === self::MODE_SKIP) {
            return [];
        }
        $changes = [];
        foreach ($incoming as $field => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if ($field === 'secret') {
                $current = $model->secret_encrypted ? rescue(fn () => Crypt::decryptString($model->secret_encrypted), null, false) : null;
            } else {
                $current = $model->{$field};
            }
            $current = $current === null ? '' : (string) $current;
            if ($field === 'phone' && Arabic::phone($current) === Arabic::phone($value)) {
                continue;
            }
            $isEmpty = $current === '' || $current === '-';
            if (($mode === self::MODE_FILL && $isEmpty) || ($mode === self::MODE_OVERWRITE && $current !== $value)) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    private function companyChanges(Account $account, array $company): array
    {
        return array_filter($company, function ($value, $field) use ($account) {
            $current = $account->{$field};
            if ($value instanceof CarbonImmutable) {
                return ! $current || ! $current->equalTo($value);
            }

            return (string) $current !== (string) $value;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, int> normalized plan name => plan id
     */
    private function planIndex(): array
    {
        $index = [];
        foreach (ServicePlan::all() as $plan) {
            $index[$this->planKey($plan->code)] = $plan->id;
            $index[$this->planKey($plan->name_ar)] = $plan->id;
            $index[$this->planKey('ftth_'.$plan->code)] = $plan->id;
        }

        return $index;
    }

    private function planKey(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(Arabic::normalize($name)));
    }

    private function key(string $header): string
    {
        return preg_replace('/[\s_\-:\.\(\)\/]+/u', '', Arabic::normalize($header));
    }

    /**
     * Decides whether "4/9/2026" means April 9 or 4 September by looking at the whole column.
     */
    private function detectDateFormat(array $rows, ?int $index, string $requested): string
    {
        if (in_array($requested, ['mdy', 'dmy'], true) || $index === null) {
            return in_array($requested, ['mdy', 'dmy'], true) ? $requested : 'mdy';
        }
        foreach ($rows as $row) {
            if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', (string) ($row['cells'][$index] ?? ''), $m)) {
                if ((int) $m[1] > 12) {
                    return 'dmy';
                }
                if ((int) $m[2] > 12) {
                    return 'mdy';
                }
            }
        }

        return 'dmy';
    }

    private function parseDate(string $value, string $format): ?CarbonImmutable
    {
        $value = trim(explode(' ', trim($value))[0]);
        try {
            if (preg_match('#^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})$#', $value, $m)) {
                return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3])->endOfDay();
            }
            if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $value, $m)) {
                $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
                [$month, $day] = $format === 'mdy' ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
                if (checkdate($month, $day, $year)) {
                    return CarbonImmutable::create($year, $month, $day)->endOfDay();
                }
            }
            if (is_numeric($value) && (int) $value > 30000 && (int) $value < 80000) {
                // Excel serial date number.
                return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->endOfDay();
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Headers, example rows and instructions for the downloadable template.
     */
    public function writeTemplate(string $path): void
    {
        $fields = ['external_id', 'full_name', 'phone', 'username', 'secret', 'serial_number', 'fat_code', 'pole_number', 'zone_code', 'location_label', 'address', 'plan', 'external_status', 'external_ends_at', 'notes'];
        $headers = array_map(fn ($f) => self::FIELDS[$f][1][0], $fields);
        $examples = [
            ['2825350', 'أحمد كريم حسن', '07701234567', 'FBG876F33P08', 'pass123', 'TDTC35980DF8', 'FAT33', '45', 'FBG0876-2', 'البيت الأول', 'حي الجامعة', 'BASIC', 'Active', '2026-10-21', ''],
            ['2825350', 'أحمد كريم حسن', '07701234567', 'FBG876F33P09', '', 'HWTC7B02D4E8', 'FAT07', '118', 'FBG0876-2', 'البيت الثاني', '', 'PLUS', 'Active', '2026-10-04', 'نفس المشترك، بيت ثانٍ'],
        ];
        $instructions = [
            'تعليمات ملف استيراد المشتركين',
            '',
            '• كل صف = حساب (يوزر). المشترك الذي عنده أكثر من بيت يُكتب في أكثر من صف بنفس رقم المشترك أو نفس الهاتف.',
            '• الحقول الإلزامية: الاسم، ومعه رقم المشترك أو رقم الهاتف. بدون يوزر يُضاف المشترك بدون حساب.',
            '• رقم الهاتف بأي صيغة: 07701234567 أو 7701234567 أو +9647701234567.',
            '• التاريخ: 2026-10-21 أو 21/10/2026. الفئة: BASIC أو PLUS أو TURBO أو PRO MAX (أو أساسي، بلس…).',
            '• أسماء الأعمدة يمكن أن تختلف: ستختار في النظام أي عمود يقابل أي حقل.',
            '• ملف التصدير من موقع الشركة (ftth) يُرفع كما هو بدون تعديل.',
            '• الاستيراد لا يحذف أي شيء، ولا يغيّر بيانات موجودة إلا إذا اخترت ذلك في خطوة الخيارات.',
            '• لا يُنشئ الاستيراد ديوناً ولا تفعيلات مالية؛ تاريخ الانتهاء يُحفظ كمعلومة من الشركة ويُستخدم لترتيب التجديد.',
            '• احذف صفوف الأمثلة قبل الرفع.',
        ];

        $this->reader->writeTemplate($path, $headers, $examples, $instructions);
    }
}
