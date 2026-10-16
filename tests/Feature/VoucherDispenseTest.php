<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\VoucherDispense;
use App\Filament\Resources\DispensedVouchers\DispensedVoucherResource;
use App\Filament\Resources\DispensedVouchers\Pages\ListDispensedVouchers;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VoucherCard;
use App\Services\VoucherStock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class VoucherDispenseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $stock = app(VoucherStock::class);
        $stock->add('internet', 35000, ['A35-0001', 'A35-0002', 'A35-0003']);
        $stock->add('internet', 45000, ['B45-0001']);
        $stock->add('sports', 20000, ['S20-0001', 'S20-0002']);
    }

    private function employee(array $permissions = []): User
    {
        $user = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_each_category_button_takes_one_card_from_that_category_only(): void
    {
        $page = Livewire::test(VoucherDispense::class)
            ->assertSee('كارت إنترنت')->assertSee('كارت قنوات رياضية')->assertSee('35,000')->assertSee('45,000')->assertSee('20,000');

        $page->call('dispense', 'internet', 45000, (string) Str::uuid())
            ->assertSet('issued.code', 'B450001')->assertSet('error', null)->assertSee('B450001')->assertSee('نسخ');
        $card = VoucherCard::where('code', 'B450001')->sole();
        $this->assertSame(['activated', $this->admin->id], [$card->status, $card->activated_by]);
        $this->assertNotNull($card->activated_at);

        // 45,000 is empty now: a clear message, and nothing is taken from 35,000.
        $page->call('dispense', 'internet', 45000, (string) Str::uuid())
            ->assertSet('issued', null)->assertSee('نفدت الكروت من هذه الفئة');
        $this->assertSame(3, VoucherCard::where('denomination', 35000)->where('status', 'available')->count());

        $page->call('dispense', 'internet', 35000, (string) Str::uuid())->assertSet('issued.code', 'A350001');
        $page->call('dispense', 'sports', 20000, (string) Str::uuid())->assertSet('issued.code', 'S200001');
        $this->assertSame(
            ['internet-35000' => 2, 'internet-45000' => 0, 'sports-20000' => 1],
            app(VoucherStock::class)->categories()->mapWithKeys(fn ($c) => ["{$c->type}-{$c->denomination}" => (int) $c->available])->all(),
        );
        $this->assertSame(3, AuditLog::where('action', 'stock.dispensed')->count());
        $this->assertSame(6, VoucherCard::count(), 'cards are never deleted');
    }

    public function test_a_card_is_never_given_twice(): void
    {
        $stock = app(VoucherStock::class);
        $key = (string) Str::uuid();
        $first = $stock->dispense('internet', 35000, $key);
        $again = $stock->dispense('internet', 35000, $key);
        $this->assertSame($first->id, $again->id, 'the same click repeated returns the same card');
        $second = $stock->dispense('internet', 35000, (string) Str::uuid());
        $third = $stock->dispense('internet', 35000);
        $this->assertCount(3, collect([$first->id, $second->id, $third->id])->unique());
        try {
            $stock->dispense('internet', 35000);
            $this->fail('the category is empty');
        } catch (BusinessRuleException $e) {
            $this->assertSame('نفدت الكروت من هذه الفئة', $e->getMessage());
        }
        // A used card cannot be activated again either.
        $this->expectException(BusinessRuleException::class);
        $stock->activate($first->fresh());
    }

    public function test_the_dispense_and_the_log_follow_their_permissions(): void
    {
        $this->actingAs($this->employee(['stock.view']));
        $this->get(VoucherDispense::getUrl())->assertForbidden();
        $this->get(DispensedVoucherResource::getUrl())->assertForbidden();
        try {
            app(VoucherStock::class)->dispense('internet', 35000);
            $this->fail('dispensed without the permission');
        } catch (AuthorizationException) {
        }
        $this->assertSame(0, VoucherCard::where('status', 'activated')->count());

        $employee = $this->employee(['stock.dispense']);
        $this->actingAs($employee);
        $this->get(VoucherDispense::getUrl())->assertOk()->assertSee('إنشاء');
        Livewire::test(VoucherDispense::class)->call('dispense', 'sports', 20000, (string) Str::uuid())->assertSet('issued.code', 'S200001');

        $this->actingAs($this->employee(['stock.history']));
        Livewire::test(ListDispensedVouchers::class)->assertCanSeeTableRecords(VoucherCard::where('status', 'activated')->get())
            ->assertSee($employee->name)->assertSee('مصروف (مستخدم)')
            ->assertDontSee('S200001', false);
        $this->assertSame(1, VoucherCard::where('status', 'activated')->count());
    }

    public function test_pasting_codes_reports_added_duplicates_and_rejected(): void
    {
        $result = app(VoucherStock::class)->add('internet', 35000, ['A35-0001', 'C35-0009', 'C35-0009', '12', 'C35-0010']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(['A350001', 'C350009'], $result['duplicates']);
        $this->assertSame(['12'], $result['rejected']);
        $this->assertSame(5, VoucherCard::where('denomination', 35000)->count());
    }
}
