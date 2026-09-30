<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Filament\Pages\ImportSubscribers;
use App\Imports\SpreadsheetReader;
use App\Imports\SubscriberImporter;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ImportRun;
use App\Models\Subscriber;
use App\Services\ActivationService;
use App\Services\SubscriberService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Uses a made-up file in the same layout as the company (ftth) export.
 */
class SubscriberImportTest extends TestCase
{
    private const HEADER = "\u{FEFF}معرف المشترك,الاسم,رقم الهاتف,اسم الاشتراك,الحالة,تاريخ الانتهاء,المنطقة,GPS,اسم الجهاز,تسلسل ONT,FAT,رقم المنفذ,معرف الاشتراك";

    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        file_put_contents($path, self::HEADER."\n".implode("\n", $lines)."\n");

        return $path;
    }

    private function sampleFile(): string
    {
        return $this->csv([
            '1000001,سارة علي حسن,7701111111,BASIC,Active,10/20/2026,FBG0876-2,"33.40,44.40",DEV-A1,SER-A1,FAT3,,5001',
            '1000002,كريم جاسم محمد,7702222222,PLUS,Expired,4/9/2026,FBG0876-2,"33.41,44.41",DEV-B1,SER-B1,FAT4,,5002',
            // Same line renewed: older and newer subscription of one device.
            '1000003,زهراء عادل كاظم,7703333333,BASIC,Expired,9/20/2026,FBG0876-2,,DEV-C1,SER-C1,FAT5,,5003',
            '1000003,زهراء عادل كاظم,7703333333,PLUS,Active,12/15/2026,FBG0876-2,,DEV-C1,SER-C1,FAT5,,5004',
            '1000004,علي فاضل,7704444444,Fiber 35,Active,10/2/2026,FBG0858-3,,DEV-D1,SER-D1,FAT9,,5005',
            ',,7705555555,BASIC,Active,10/2/2026,FBG0858-3,,DEV-E1,SER-E1,FAT9,,5006',   // no name
            ',بلا هوية,,BASIC,Active,10/2/2026,FBG0858-3,,DEV-F1,SER-F1,FAT9,,5007',      // no id and no phone
        ]);
    }

    private function importer(): SubscriberImporter
    {
        return app(SubscriberImporter::class);
    }

    private function mapping(string $path): array
    {
        return $this->importer()->suggestMapping(app(SpreadsheetReader::class)->read($path, 'x.csv')['headers']);
    }

    public function test_company_export_columns_are_mapped_automatically(): void
    {
        $mapping = $this->mapping($this->sampleFile());

        $this->assertSame(0, $mapping['external_id']);
        $this->assertSame(1, $mapping['full_name']);
        $this->assertSame(2, $mapping['phone']);
        $this->assertSame(3, $mapping['plan']);
        $this->assertSame(5, $mapping['external_ends_at']);
        $this->assertSame(8, $mapping['username']);
        $this->assertSame(9, $mapping['serial_number']);
        $this->assertSame(10, $mapping['fat_code']);
        $this->assertNull($mapping['secret']);
    }

    public function test_preview_counts_new_records_merges_and_errors_without_writing(): void
    {
        $path = $this->sampleFile();

        $plan = $this->importer()->analyze($path, 'x.csv', $this->mapping($path));

        $this->assertSame(7, $plan->stats['rows_total']);
        $this->assertSame(4, $plan->stats['subscribers_create']);
        $this->assertSame(4, $plan->stats['accounts_create']);
        $this->assertSame(1, $plan->stats['rows_merged']);
        $this->assertSame(2, $plan->stats['rows_error']);
        $this->assertStringContainsString('الاسم مفقود', implode(' ', $plan->rows[7]['messages']));
        $this->assertStringContainsString('لا يوجد رقم مشترك ولا رقم هاتف', implode(' ', $plan->rows[8]['messages']));
        $this->assertStringContainsString('Fiber 35', implode(' ', $plan->rows[6]['messages']));
        $this->assertSame(0, Subscriber::count());
    }

    public function test_import_creates_subscribers_and_accounts_with_company_data_and_audit(): void
    {
        $path = $this->sampleFile();

        $run = $this->importer()->execute($path, 'ftth.csv', $this->mapping($path));

        $this->assertSame('success', $run->status);
        $this->assertSame(4, Subscriber::count());
        $zahraa = Subscriber::where('external_id', '1000003')->sole();
        $this->assertSame('07703333333', $zahraa->phone);
        $this->assertStringStartsWith('C-', $zahraa->code);
        $line = $zahraa->accounts()->sole();
        $this->assertSame('DEV-C1', $line->username);
        $this->assertSame('plus', $line->currentPlan->code);
        $this->assertSame('active', $line->external_status);
        $this->assertSame('2026-12-15', $line->service_ends_at->format('Y-m-d'));
        $this->assertSame('2026-04-09', Account::where('username', 'DEV-B1')->value('external_ends_at') ? Account::where('username', 'DEV-B1')->first()->external_ends_at->format('Y-m-d') : null);
        $this->assertNull(Account::where('username', 'DEV-D1')->first()->current_plan_id);
        $this->assertSame('Fiber 35', Account::where('username', 'DEV-D1')->first()->external_plan);
        $this->assertTrue(AuditLog::where('action', 'subscribers.imported')->where('entity_id', $run->id)->exists());
        $this->assertSame(4, AuditLog::where('action', 'subscriber.imported')->count());
    }

    public function test_importing_the_same_file_twice_creates_no_duplicates(): void
    {
        $path = $this->sampleFile();
        $this->importer()->execute($path, 'x.csv', $this->mapping($path));

        $plan = $this->importer()->analyze($path, 'x.csv', $this->mapping($path));

        $this->assertSame(0, $plan->stats['subscribers_create']);
        $this->assertSame(0, $plan->stats['accounts_create']);
        $this->assertSame(4, $plan->stats['subscribers_same']);
        $this->assertFalse($plan->hasChanges());
    }

    public function test_an_existing_subscriber_is_matched_by_phone_and_left_untouched_by_default(): void
    {
        $existing = app(SubscriberService::class)->create(['full_name' => 'سارة (اسم قديم)', 'phone' => '0770 111 1111']);
        $path = $this->sampleFile();

        $this->importer()->execute($path, 'x.csv', $this->mapping($path));

        $this->assertSame(4, Subscriber::count());
        $this->assertSame('سارة (اسم قديم)', $existing->fresh()->full_name);
        $this->assertNull($existing->fresh()->external_id);
        $this->assertSame('DEV-A1', $existing->accounts()->sole()->username);
    }

    public function test_fill_mode_only_fills_empty_fields(): void
    {
        $existing = app(SubscriberService::class)->create(['full_name' => 'سارة (اسم قديم)', 'phone' => '07701111111'], ['username' => 'DEV-A1']);
        $path = $this->sampleFile();

        $this->importer()->execute($path, 'x.csv', $this->mapping($path), ['mode' => SubscriberImporter::MODE_FILL]);

        $existing->refresh();
        $account = $existing->accounts()->sole();
        $this->assertSame('سارة (اسم قديم)', $existing->full_name);
        $this->assertSame('1000001', $existing->external_id);
        $this->assertSame('SER-A1', $account->serial_number);
        $this->assertSame('FAT3', $account->fat_code);
        $this->assertNull($account->external_ends_at, 'company data needs its own option');
    }

    public function test_overwrite_mode_replaces_values_and_company_option_updates_expiry(): void
    {
        $existing = app(SubscriberService::class)->create(['full_name' => 'سارة (اسم قديم)', 'phone' => '07701111111'], ['username' => 'DEV-A1', 'fat_code' => 'FAT-OLD']);
        $path = $this->sampleFile();

        $this->importer()->execute($path, 'x.csv', $this->mapping($path), ['mode' => SubscriberImporter::MODE_OVERWRITE, 'update_company_data' => true]);

        $account = $existing->accounts()->sole();
        $this->assertSame('سارة علي حسن', $existing->fresh()->full_name);
        $this->assertSame('FAT3', $account->fat_code);
        $this->assertSame('2026-10-20', $account->service_ends_at->format('Y-m-d'));
        $this->assertTrue(AuditLog::where('action', 'subscriber.updated')->where('reason', 'استيراد من ملف')->exists());
    }

    public function test_a_username_owned_by_another_subscriber_is_an_error_not_a_takeover(): void
    {
        app(SubscriberService::class)->create(['full_name' => 'شخص آخر', 'phone' => '07809999999'], ['username' => 'DEV-A1']);
        $path = $this->sampleFile();

        $plan = $this->importer()->analyze($path, 'x.csv', $this->mapping($path));

        $this->assertSame('error', $plan->rows[2]['status']);
        $this->assertStringContainsString('مسجل لمشترك آخر', implode(' ', $plan->rows[2]['messages']));
    }

    public function test_a_renewal_after_import_is_queued_after_the_company_expiry(): void
    {
        $path = $this->sampleFile();
        $this->importer()->execute($path, 'x.csv', $this->mapping($path));

        $activation = app(ActivationService::class)->activate(Account::where('username', 'DEV-A1')->sole(), $this->plan(), ActivationKind::Full30);

        $this->assertSame('2026-10-20 23:59:59', $activation->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_the_template_downloads_and_imports_back(): void
    {
        $response = $this->get(route('import.subscribers.template'));
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $sheet = app(SpreadsheetReader::class)->read($path, 'template.xlsx');
        $plan = $this->importer()->plan($sheet['rows'], $this->importer()->suggestMapping($sheet['headers']));

        $this->assertSame(1, $plan->stats['subscribers_create']);
        $this->assertSame(2, $plan->stats['accounts_create']);
        $this->assertSame(0, $plan->stats['rows_error']);
    }

    public function test_the_import_page_runs_the_whole_flow(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('ftth.csv', file_get_contents($this->sampleFile()));

        Livewire::test(ImportSubscribers::class)
            ->set('data.file', $file)
            ->callAction('upload')
            ->assertSet('step', 2)
            ->assertSet('rowCount', 7)
            ->callAction('preview')
            ->assertSet('step', 3)
            ->assertSee('زهراء عادل كاظم')
            ->callAction('import')
            ->assertSet('step', 4);

        $this->assertSame(4, Subscriber::count());
        $this->assertSame('success', ImportRun::sole()->status);
    }
}
