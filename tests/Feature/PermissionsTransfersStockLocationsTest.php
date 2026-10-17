<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DocumentStatus;
use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\AccountingPeriodsPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmployeeHome;
use App\Filament\Pages\EmployeePermissions;
use App\Filament\Pages\SubscriberLocationPage;
use App\Filament\Resources\CardAccounts\CardAccountResource;
use App\Filament\Resources\CardAccounts\CardOperationsResource;
use App\Filament\Resources\CardAccounts\Pages\ListCardAccounts;
use App\Filament\Resources\DeviceSales\DeviceSaleResource;
use App\Filament\Resources\DeviceSales\Pages\ListDeviceSales;
use App\Filament\Resources\EmployeeTransfers\EmployeeTransferResource;
use App\Filament\Resources\EmployeeTransfers\Pages\ListEmployeeTransfers;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Filament\Resources\VoucherCards\Pages\ListVoucherCards;
use App\Filament\Resources\VoucherCards\VoucherCardResource;
use App\Filament\Widgets\CardBalances;
use App\Filament\Widgets\PeriodStats;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\DeviceType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FundTransfer;
use App\Models\MoneyAccount;
use App\Models\MoneyAccountReset;
use App\Models\PeriodClosing;
use App\Models\SubscriberLocation;
use App\Models\User;
use App\Models\VoucherCard;
use App\Services\AccountingPeriods;
use App\Services\DebtService;
use App\Services\EmployeeFinance;
use App\Services\InternalTransfers;
use App\Services\Ledger;
use App\Services\LocationService;
use App\Services\PermissionService;
use App\Services\Settings;
use App\Services\TreasuryService;
use App\Services\VoucherStock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PermissionsTransfersStockLocationsTest extends TestCase
{
    private function employee(string $name = 'أحمد', array $extra = []): User
    {
        $user = User::factory()->create(['name' => $name, 'branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');
        $user->givePermissionTo($extra);

        return $user;
    }

    private function refused(callable $call, string $exception = BusinessRuleException::class): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
    }

    private function totalBalance(): int
    {
        return (int) MoneyAccount::get()->sum(fn (MoneyAccount $m) => app(TreasuryService::class)->balance($m));
    }

    // ---- Permissions ----

    public function test_taking_away_sales_hides_the_menu_and_the_server_refuses(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee);
        $this->assertTrue(DeviceSaleResource::canViewAny());
        $this->get(DeviceSaleResource::getUrl())->assertOk();

        // Only «add a sale» taken away: the list stays, the button goes, the server refuses.
        $this->actingAs($this->admin);
        $chosen = array_diff(app(PermissionService::class)->effective($employee), ['sales.create']);
        app(PermissionService::class)->apply($employee, $chosen);
        $employee = $employee->fresh();
        $this->actingAs($employee);
        $this->assertFalse($employee->can('sales.create'));
        $this->assertTrue($employee->can('sales.view'));
        Livewire::test(ListDeviceSales::class)->assertActionHidden('newSale');
        $this->assertTrue($this->employee('آخر')->can('sales.create'), 'the other employees keep it');

        // The whole of «المبيعات» taken away: the menu and the page go.
        $this->actingAs($this->admin);
        app(PermissionService::class)->apply($employee, array_diff($chosen, ['sales.view']));
        $this->actingAs($employee->fresh());
        $this->assertFalse(DeviceSaleResource::canViewAny());
        $this->get(DeviceSaleResource::getUrl())->assertForbidden();

        $log = AuditLog::where('action', 'user.permissions_changed')->latest('id')->first();
        $this->assertSame(['sales.view'], $log->old_values['removed']);
    }

    public function test_permissions_are_managed_from_the_page_and_nobody_raises_their_own(): void
    {
        $employee = $this->employee();
        Livewire::test(EmployeePermissions::class)
            ->fillForm(['user_id' => $employee->id])
            ->assertFormSet([EmployeePermissions::key('حسابات البطاقات') => []])
            ->fillForm([EmployeePermissions::key('حسابات البطاقات') => ['cards.view', 'cards.transfer']])
            ->call('save');
        $this->assertTrue($employee->fresh()->can('cards.transfer'));
        $this->assertFalse($employee->fresh()->can('cards.reset'));

        // A manager who is not the admin: not their own permissions, and nothing they do not hold.
        $manager = $this->employee('مشرف', ['users.manage']);
        $this->actingAs($manager);
        $this->refused(fn () => app(PermissionService::class)->apply($manager, ['users.manage', 'settings.manage']));
        $this->refused(fn () => app(PermissionService::class)->apply($employee->fresh(), [...app(PermissionService::class)->effective($employee->fresh()), 'settings.manage']));
        $this->refused(fn () => app(PermissionService::class)->apply($this->admin, []));

        $this->actingAs($this->employee('موظف'));
        $this->refused(fn () => app(PermissionService::class)->apply($employee, []), AuthorizationException::class);
        $this->get(EmployeePermissions::getUrl())->assertForbidden();
    }

    // ---- Transfers between employees ----

    public function test_a_transfer_between_employees_moves_custody_once_and_can_be_voided(): void
    {
        $omar = $this->employee('عمر');
        $ahmed = $this->employee('أحمد');
        $finance = app(EmployeeFinance::class);
        app(TreasuryService::class)->openingBalance($finance->custodyAccount($omar), 100000);
        app(TreasuryService::class)->openingBalance($finance->custodyAccount($ahmed), 50000);
        $total = $this->totalBalance();
        $debts = Debt::count();

        $key = (string) Str::uuid();
        $transfers = app(InternalTransfers::class);
        $transfer = $transfers->betweenEmployees($omar, $ahmed, 20000, 'تسليم', $key);
        $transfers->betweenEmployees($omar, $ahmed, 20000, 'تسليم', $key);

        $this->assertSame([80000, 70000], [$finance->custodyBalance($omar), $finance->custodyBalance($ahmed)]);
        $this->assertSame(1, FundTransfer::where('kind', 'custody')->count(), 'the same request twice transfers once');
        $this->assertSame([$this->admin->id, 'custody'], [$transfer->created_by, $transfer->kind]);
        $this->assertMatchesRegularExpression('/^F-\d{4}-\d{6}$/', $transfer->number);
        $this->assertSame($total, $this->totalBalance(), 'not income, not an expense');
        $this->assertSame($debts, Debt::count());

        $this->refused(fn () => $transfers->betweenEmployees($omar, $omar, 1000));
        $this->refused(fn () => $transfers->betweenEmployees($omar, $ahmed, 0));
        $this->refused(fn () => $transfers->betweenEmployees($omar, $ahmed, 500000), BusinessRuleException::class);

        Livewire::test(ListEmployeeTransfers::class)->assertSee('عمر')->assertSee('20,000')
            ->filterTable('employee', $ahmed->id)->assertCountTableRecords(1)
            ->callTableAction('void', $transfer, data: ['reason' => 'خطأ']);
        $this->assertSame([100000, 50000], [$finance->custodyBalance($omar), $finance->custodyBalance($ahmed)]);
        $this->assertSame(DocumentStatus::Voided, $transfer->fresh()->status);

        $this->actingAs($this->employee('موظف'));
        $this->refused(fn () => app(InternalTransfers::class)->betweenEmployees($omar, $ahmed, 1000), AuthorizationException::class);
        $this->get(EmployeeTransferResource::getUrl())->assertForbidden();
    }

    // ---- Cards ----

    public function test_cards_transfer_and_reset_with_the_history_kept(): void
    {
        $treasury = app(TreasuryService::class);
        $qi = $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'كي كارد');
        $super = $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'سوبر كي');
        $treasury->openingBalance($qi, 100000);
        $treasury->openingBalance($super, 50000);

        $cards = app(InternalTransfers::class);
        $cards->betweenCards($qi, $super, 50000, 'تحويل', null, $key = (string) Str::uuid());
        $cards->betweenCards($qi, $super, 50000, 'تحويل', null, $key);
        $this->assertSame([50000, 100000], [$treasury->balance($qi), $treasury->balance($super)]);
        $this->refused(fn () => $cards->betweenCards($qi, $this->cash(), 1000));

        $moves = $treasury->movements($qi, CarbonImmutable::today(), CarbonImmutable::today());
        $this->assertSame(['in' => 100000, 'out' => 50000, 'current' => 50000], $moves);
        Livewire::test(ListCardAccounts::class)->assertSee('كي كارد')->assertSee('100,000');

        $reset = $cards->resetCard($super, 'سحب الرصيد نقداً', $resetKey = (string) Str::uuid());
        $cards->resetCard($super, 'سحب الرصيد نقداً', $resetKey);
        $this->assertSame(0, $treasury->balance($super));
        $this->assertSame([1, 100000], [MoneyAccountReset::count(), $reset->amount]);
        $statement = $treasury->statement($super);
        $this->assertCount(3, $statement['rows'], 'the old movements are still there, plus the reset');
        $this->assertSame('تصفير رصيد بطاقة', $statement['rows']->last()['label']);
        $this->refused(fn () => $cards->resetCard($super, 'مرة ثانية'));
        $this->assertSame(1, AuditLog::where('action', 'money_account.reset')->count());

        $cards->voidReset($reset, 'خطأ');
        $this->assertSame(100000, $treasury->balance($super));

        $this->actingAs($this->employee());
        $this->refused(fn () => app(InternalTransfers::class)->resetCard($super, 'x'), AuthorizationException::class);
        $this->refused(fn () => app(InternalTransfers::class)->betweenCards($qi, $super, 1000), AuthorizationException::class);
    }

    public function test_the_card_balances_section_shows_the_total_and_resets_every_card(): void
    {
        $treasury = app(TreasuryService::class);
        $qi = $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'كي كارد');
        $super = $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'سوبر كي');
        $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'ماستر');
        $treasury->openingBalance($qi, 70000);
        $treasury->openingBalance($super, 30000);
        $cash = $treasury->balance($this->cash());

        Livewire::test(CardBalances::class)->assertSee('رصيد البطاقات')->assertSee('100,000')
            ->assertActionVisible('resetAll')
            ->callAction('resetAll', data: ['reason' => 'سحب نقدي', 'idempotency_key' => $key = (string) Str::uuid()]);
        $this->assertSame([0, 0], [$treasury->balance($qi), $treasury->balance($super)]);
        $this->assertSame(2, MoneyAccountReset::count(), 'the empty card is not reset');
        $this->assertSame($cash, $treasury->balance($this->cash()), 'nothing else moves');

        app(InternalTransfers::class)->resetAllCards('سحب نقدي', $key);
        $this->assertSame(2, MoneyAccountReset::count(), 'the same request twice resets once');
        $this->refused(fn () => app(InternalTransfers::class)->resetAllCards('مرة ثانية'));
        Livewire::test(CardBalances::class)->assertSee('0 د.ع');
        Livewire::test(Dashboard::class)->assertSeeLivewire(CardBalances::class);

        $employee = $this->employee('موظف', ['cards.view']);
        $this->actingAs($employee);
        Livewire::test(CardBalances::class)->assertActionHidden('resetAll');
        $this->get(EmployeeHome::getUrl())->assertOk()->assertSee('رصيد البطاقات');
        $this->refused(fn () => app(InternalTransfers::class)->resetAllCards('x'), AuthorizationException::class);
    }

    // ---- Accounting periods ----

    public function test_purchases_and_sales_periods_close_separately_and_nothing_moves(): void
    {
        $treasury = app(TreasuryService::class);
        $treasury->openingBalance($this->cash(), 500000);
        app(Settings::class)->set('periods.purchases_start', '2026-09-15');
        app(Settings::class)->set('periods.sales_start', '2026-09-15');
        $treasury->recordExpense(ExpenseCategory::first(), 'كيبل', 50000, $this->cash());
        $treasury->recordDeviceSale(DeviceType::first(), 'راوتر', 60000, 1, null, $this->cash());
        $balances = $this->totalBalance();

        $periods = app(AccountingPeriods::class);
        $t = $periods->totals(CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-10-14'));
        $this->assertSame([50000, 60000, 10000], [$t['purchases'], $t['sales'], $t['difference']]);

        $this->travelTo(now()->addMinute()); // the clock is frozen in tests; a reset comes after what it counts
        $periods->close(['purchases'], CarbonImmutable::today());
        $closing = PeriodClosing::sole();
        $this->assertSame(['purchases', 50000, 1, '2026-09-15', '2026-09-21'], [$closing->scope, $closing->total, $closing->operations, $closing->starts_on->toDateString(), $closing->ends_on->toDateString()]);
        $this->assertTrue($periods->currentStart('purchases')->equalTo(now()), 'a reset ends the period now');
        $this->assertSame('2026-09-15', $periods->currentStart('sales')->toDateString(), 'sales stay open');

        $this->assertSame($balances, $this->totalBalance(), 'closing changes no balance');
        $this->assertSame(1, Expense::count(), 'nothing is deleted');

        // Later the same day: the new purchases period has it, the closed one does not lose or double it.
        $this->travelTo(now()->addHours(2));
        $treasury->recordExpense(ExpenseCategory::first(), 'بنزين', 7000, $this->cash());
        $this->travelTo(now()->addMinute());
        $open = $periods->totals($periods->currentStart('purchases'), CarbonImmutable::now(), exact: true);
        $this->assertSame(7000, $open['purchases']);
        $this->assertSame(50000, $closing->fresh()->total);

        $this->travelTo(now()->addDays(3));
        $periods->close(['purchases', 'sales'], CarbonImmutable::today());
        $this->assertSame(3, PeriodClosing::count());
        $this->assertSame(60000, PeriodClosing::where('scope', 'sales')->value('total'));
        $this->assertSame(7000, PeriodClosing::where('scope', 'purchases')->latest('id')->value('total'));
        $this->refused(fn () => $periods->close(['sales'], CarbonImmutable::today()->subDays(5)));

        Livewire::test(AccountingPeriodsPage::class)->assertSee('الربح والخسارة')
            ->call('showClosing', $closing->id)->assertSee('50,000');
        $this->assertSame(3, AuditLog::where('action', 'period.closed')->count());

        // «تصفير» from the dashboard, and the dashboard counting since the last one.
        $this->travelTo(now()->addHour());
        $treasury->recordDeviceSale(DeviceType::first(), 'مقوي', 15000, 1, null, $this->cash());
        $this->travelTo(now()->addMinute());
        Livewire::test(PeriodStats::class, ['pageFilters' => ['period' => 'open']])->assertSee('15,000');
        Livewire::test(Dashboard::class)->assertActionVisible('closeSales')
            ->callAction('closeSales', data: ['ends_on' => CarbonImmutable::today()->toDateString()]);
        $this->assertSame(15000, PeriodClosing::where('scope', 'sales')->latest('id')->value('total'));
        Livewire::test(PeriodStats::class, ['pageFilters' => ['period' => 'open']])->assertDontSee('15,000');

        $periods->openNew('sales', CarbonImmutable::parse('2026-10-15'));
        $this->assertSame('2026-10-15', $periods->currentStart('sales')->toDateString());

        $this->actingAs($this->employee());
        $this->refused(fn () => app(AccountingPeriods::class)->close(['sales'], CarbonImmutable::today()), AuthorizationException::class);
    }

    // ---- Card stock ----

    public function test_card_stock_adds_unique_codes_and_activates_once(): void
    {
        $stock = app(VoucherStock::class);
        $result = $stock->add('internet', 35000, ['1111-2222-3333', '111122223333', '4444 5555 6666', '7777888899990000']);
        $this->assertSame(3, $result['added']);
        $this->assertSame(['111122223333'], $result['duplicates']);
        $again = $stock->add('internet', 35000, ['4444-5555-6666', '12121212']);
        $this->assertSame([1, ['444455556666']], [$again['added'], $again['duplicates']]);
        $stock->add('sports', 20000, ['SP-0001']);

        $card = VoucherCard::where('code', '111122223333')->sole();
        $stock->activate($card);
        $this->refused(fn () => $stock->activate($card));
        $this->assertSame('activated', $card->fresh()->status);

        $row = $stock->summary()->first(fn ($r) => $r->type === 'internet' && (int) $r->denomination === 35000);
        $this->assertSame([4, 3, 1], [(int) $row->total, (int) $row->available, (int) $row->activated]);
        Livewire::test(ListVoucherCards::class)->assertSee('كارت إنترنت')
            ->filterTable('status', 'available')->assertCountTableRecords(4)
            ->searchTable('SP-0001')->assertCountTableRecords(1);

        $employee = $this->employee('موظف', ['stock.view']);
        $this->actingAs($employee);
        $this->assertStringStartsWith('•', $card->visibleCode(), 'codes are masked without «إظهار أرقام الكارتات»');
        $this->refused(fn () => app(VoucherStock::class)->activate(VoucherCard::where('status', 'available')->first()), AuthorizationException::class);
    }

    // ---- Subscriber locations ----

    public function test_a_house_location_is_saved_from_gps_without_a_photo_and_seen_by_another_employee(): void
    {
        $account = $this->account('زينب كريم');
        $subscriber = $account->subscriber;
        $employee = $this->employee('عمر');
        $this->actingAs($employee);

        // No location yet: nothing pretends to be one.
        $this->get(SubscriberResource::getUrl('view', ['record' => $subscriber]))->assertOk()->assertSee('لا يوجد موقع محفوظ')->assertSee('إضافة موقع');
        Livewire::withQueryParams(['subscriber' => $subscriber->id])->test(SubscriberLocationPage::class)
            ->assertSee('لا يوجد موقع محفوظ')->assertViewHas('saved', null);

        Livewire::withQueryParams(['subscriber' => $subscriber->id, 'mode' => 'edit'])->test(SubscriberLocationPage::class)
            ->assertSet('mode', 'edit')
            ->call('saveLocation', 33.3128456, 44.3615221, 8.4, 'gps', false)
            ->assertSet('mode', 'view')->assertDispatched('location-saved');

        $location = SubscriberLocation::sole();
        $this->assertSame([33.3128456, 44.3615221, 8, 'gps', $employee->id], [$location->latitude, $location->longitude, $location->accuracy_m, $location->source, $location->saved_by]);
        $this->assertNull($location->photo_path, 'the photo is optional');
        $this->assertSame('location.created', AuditLog::where('entity_type', 'SubscriberLocation')->sole()->action);

        // A new employee, who never went there, opens it and gets directions to the same point.
        $this->actingAs($this->employee('موظف جديد'));
        $this->get(SubscriberResource::getUrl('view', ['record' => $subscriber]))->assertOk()->assertSee('فتح الاتجاهات')
            ->assertSee('https://www.google.com/maps/dir/?api=1&amp;destination=33.3128456,44.3615221', false);
        Livewire::withQueryParams(['subscriber' => $subscriber->id])->test(SubscriberLocationPage::class)
            ->assertViewHas('saved', ['lat' => 33.3128456, 'lng' => 44.3615221, 'accuracy' => 8]);
    }

    public function test_replacing_a_location_needs_confirmation_and_permission_and_is_audited(): void
    {
        $subscriber = $this->account()->subscriber;
        $locations = app(LocationService::class);
        $locations->save($subscriber, 33.30, 44.40, 12, 'gps');

        $this->refused(fn () => $locations->save($subscriber, 33.31, 44.41, null, 'map'));
        $locations->save($subscriber, 33.31, 44.41, null, 'map', replace: true);
        $this->assertSame([33.31, 44.41, 'map'], [SubscriberLocation::sole()->latitude, SubscriberLocation::sole()->longitude, SubscriberLocation::sole()->source]);
        $update = AuditLog::where('action', 'location.updated')->sole();
        $this->assertSame(33.3, (float) $update->old_values['lat']);
        $this->assertSame(33.31, (float) $update->new_values['lat']);

        $this->refused(fn () => $locations->save($subscriber, 0.0, 0.0, null, 'gps', true));
        $this->refused(fn () => $locations->save($subscriber, 95.0, 44.0, null, 'gps', true));

        // An employee may add and edit but not delete by default.
        $this->actingAs($this->employee());
        $this->refused(fn () => app(LocationService::class)->delete($subscriber), AuthorizationException::class);
        $this->actingAs($this->admin);
        $locations->delete($subscriber, 'انتقل');
        $this->assertSame(0, SubscriberLocation::count());
        $this->assertSame(1, AuditLog::where('action', 'location.deleted')->count());
    }

    public function test_the_house_photo_is_optional_private_and_follows_its_permissions(): void
    {
        Storage::fake('local');
        $subscriber = $this->account()->subscriber;
        $employee = $this->employee('عمر');
        $this->actingAs($employee);

        Livewire::withQueryParams(['subscriber' => $subscriber->id])->test(SubscriberLocationPage::class)
            ->set('photo', UploadedFile::fake()->image('house.jpg', 800, 600))
            ->call('savePhoto')->assertHasNoErrors();
        $location = SubscriberLocation::sole();
        $this->assertNotNull($location->photo_path);
        $this->assertFalse($location->hasCoordinates(), 'a photo without a location is allowed too');
        Storage::disk('local')->assertExists($location->photo_path);
        $this->get(route('subscribers.house-photo', $subscriber))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Not an image: refused.
        Livewire::withQueryParams(['subscriber' => $subscriber->id])->test(SubscriberLocationPage::class)
            ->set('photo', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->call('savePhoto')->assertHasErrors('photo');

        // Replacing needs «حذف أو استبدال الصورة», which employees do not have by default.
        $this->refused(fn () => app(LocationService::class)->setPhoto($subscriber, UploadedFile::fake()->image('b.jpg')), AuthorizationException::class);

        // Without the location permissions: nothing shown, nothing reachable directly.
        $this->actingAs($this->admin);
        $denied = $this->employee('بلا صلاحية');
        app(PermissionService::class)->apply($denied, array_diff(app(PermissionService::class)->effective($denied),
            ['locations.view', 'locations.create', 'locations.update', 'locations.directions', 'photos.view', 'photos.add']));
        app(LocationService::class)->save($subscriber, 33.3, 44.4, 5, 'gps');
        $this->actingAs($denied->fresh());
        $this->get(route('subscribers.house-photo', $subscriber))->assertForbidden();
        $this->get(SubscriberLocationPage::getUrl(['subscriber' => $subscriber->id]))->assertForbidden();
        $this->get(SubscriberResource::getUrl('view', ['record' => $subscriber]))->assertOk()->assertDontSee('موقع المشترك')->assertDontSee('33.3000000');
        $this->refused(fn () => app(LocationService::class)->save($subscriber, 33.2, 44.2, null, 'gps', true), AuthorizationException::class);
        auth()->logout();
        $this->get(route('subscribers.house-photo', $subscriber))->assertRedirect();
    }

    public function test_the_new_screens_open_for_the_admin(): void
    {
        foreach ([
            EmployeePermissions::getUrl(), EmployeeTransferResource::getUrl(),
            CardAccountResource::getUrl(), CardOperationsResource::getUrl(),
            AccountingPeriodsPage::getUrl(), VoucherCardResource::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('subscribers.export'))->assertOk()->assertDownload();
        $this->actingAs($this->employee());
        $this->get(route('subscribers.export'))->assertForbidden();
    }

    public function test_none_of_this_touches_the_debts(): void
    {
        $account = $this->account();
        app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual);
        app(DebtService::class)->create($account, 35000, DebtBucket::Secondary, DebtSource::Manual);
        $before = [$this->balanceOf(Ledger::AR_PRIMARY), $this->balanceOf(Ledger::AR_SECONDARY), Debt::pluck('balance', 'id')->all()];

        app(LocationService::class)->save($account->subscriber, 33.3, 44.4, 5, 'gps');
        app(VoucherStock::class)->add('internet', 35000, ['99998888']);
        app(AccountingPeriods::class)->close(['purchases', 'sales'], CarbonImmutable::today());

        $this->assertSame($before, [$this->balanceOf(Ledger::AR_PRIMARY), $this->balanceOf(Ledger::AR_SECONDARY), Debt::pluck('balance', 'id')->all()]);
    }
}
