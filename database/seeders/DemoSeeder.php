<?php

namespace Database\Seeders;

use App\Enums\ActivationKind;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Enums\Settlement;
use App\Models\MoneyAccount;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\ActivationService;
use App\Services\DebtService;
use App\Services\PaymentService;
use App\Services\SubscriberService;
use App\Services\TreasuryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample data for trying the system: php artisan db:seed --class=DemoSeeder
 * Never run it on the live database.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $real = CarbonImmutable::now();
        $at = fn (string $offset) => $this->travel($real->modify($offset));

        $admin = User::first();
        auth()->login($admin);
        $employee = User::firstOrCreate(['username' => 'hasan'], [
            'branch_id' => $admin->branch_id, 'name' => 'حسن', 'password' => 'hasan-2026', 'is_active' => true,
        ]);
        $employee->assignRole('employee');

        $treasury = app(TreasuryService::class);
        $cash = MoneyAccount::where('kind', MoneyAccountKind::Cash)->first();
        $company = MoneyAccount::where('kind', MoneyAccountKind::Company)->first();
        $wallet = MoneyAccount::where('kind', MoneyAccountKind::Electronic)->first()
            ?? $treasury->createMoneyAccount($admin->branch_id, MoneyAccountKind::Electronic, 'زين كاش – المدير', 'المدير');

        $at('-12 days');
        $treasury->openingBalance($cash, 2_000_000);
        $treasury->transfer($cash, $company, 1_200_000, 'TOPUP-1001');

        $people = app(SubscriberService::class);
        $activations = app(ActivationService::class);
        $payments = app(PaymentService::class);
        $plan = fn (string $code) => ServicePlan::where('code', $code)->first();

        $ahmed = $people->create(['full_name' => 'أحمد كريم', 'phone' => '07701234567', 'address' => 'حي الجامعة'],
            ['username' => 'ahmed.k01', 'secret' => 'a1b2c3', 'serial_number' => 'ZTEG4A1C9F21', 'fat_code' => 'FAT-12', 'pole_number' => '45', 'location_label' => 'البيت الأول']);
        $ahmed2 = $people->createAccount($ahmed, ['username' => 'ahmed.k02', 'secret' => 'z9y8', 'serial_number' => 'HWTC7B02D4E8', 'fat_code' => 'FAT-07', 'pole_number' => '118', 'location_label' => 'البيت الثاني']);
        $ali = $people->create(['full_name' => 'علي حسين', 'phone' => '07902223141'], ['username' => 'ali.h77', 'secret' => 'ali77', 'fat_code' => 'FAT-03', 'pole_number' => '22']);
        $zainab = $people->create(['full_name' => 'زينب عادل', 'phone' => '07714048820'], ['username' => 'zainab.a12', 'secret' => 'zz12', 'fat_code' => 'FAT-12', 'pole_number' => '47']);
        $mustafa = $people->create(['full_name' => 'مصطفى جاسم', 'phone' => '07821309954'], ['username' => 'mustafa.j3', 'secret' => 'mj3', 'fat_code' => 'FAT-05', 'pole_number' => '9']);
        $noor = $people->create(['full_name' => 'نور الهدى سالم', 'phone' => '07508182203'], ['username' => 'noor.s08', 'secret' => 'ns08', 'fat_code' => 'FAT-03', 'pole_number' => '30']);
        $haider = $people->create(['full_name' => 'حيدر فاضل', 'phone' => '07730096617'], ['username' => 'haider.f21', 'secret' => 'hf21', 'fat_code' => 'FAT-09', 'pole_number' => '61']);

        $acc = fn ($subscriber) => $subscriber->accounts()->first();

        $at('-9 days');
        $a1 = $activations->activate($acc($ahmed), $plan('basic'), ActivationKind::Partial7);
        $activations->activate($acc($haider), $plan('pro_max'), ActivationKind::Partial7);
        $at('-8 days');
        $aAli = $activations->activate($acc($ali), $plan('basic'), ActivationKind::Partial7);
        $at('-5 days');
        $payments->record($acc($ahmed), 20000, PaymentMethod::Cash, $cash);
        $at('-2 days');
        app(DebtService::class)->transfer($a1->debt->fresh(), 'لم يسدد الباقي');
        $activations->activate($ahmed2, $plan('plus'), ActivationKind::Partial7);
        $at('-7 days');
        $activations->activate($acc($zainab), $plan('plus'), ActivationKind::Partial7);
        $activations->activate($acc($mustafa), $plan('turbo'), ActivationKind::Partial7);
        $at('-6 days');
        $activations->activate($acc($noor), $plan('basic'), ActivationKind::Partial7);

        $this->travel($real->setTime(9, 15));
        auth()->login($employee);
        $payments->record($acc($ali), 20000, PaymentMethod::Electronic, $wallet, receiverName: 'المدير', reference: 'ZC-88412077', transferRemainder: true);
        $activations->activate($people->create(['full_name' => 'حسين علي', 'phone' => '07815550192'], ['username' => 'hussein.a44', 'secret' => 'h44'])->accounts()->first(),
            $plan('turbo'), ActivationKind::Full30, settlement: Settlement::Paid, moneyAccount: $cash);
        $treasury->recordExpense(\App\Models\ExpenseCategory::where('name_ar', 'راوتر وأجهزة')->first(), 'شراء راوتر TP-Link', 42000, $cash, 'محل الأمين');
        $treasury->recordDeviceSale(\App\Models\DeviceType::where('key', 'router')->first(), 'راوتر TP-Link', 55000, cost: 42000, into: $cash, buyerName: 'زبون محل');
        $payments->record($acc($zainab), lines: [
            ['method' => \App\Models\PaymentMethodType::where('code', 'cash')->first(), 'amount' => 25000],
            ['method' => \App\Models\PaymentMethodType::where('code', 'zain_cash')->first(), 'money_account' => $wallet, 'amount' => 20000, 'receiver' => 'المدير', 'reference' => 'ZC-55120'],
        ]);

        $this->travel($real);
        auth()->logout();
    }

    private function travel(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
