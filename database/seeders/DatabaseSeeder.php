<?php

namespace Database\Seeders;

use App\Enums\MoneyAccountKind;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\DeviceType;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Ledger;
use App\Services\TreasuryService;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reference data every installation needs. Safe to run more than once.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Currency::updateOrCreate(['code' => 'IQD'], ['name_ar' => 'دينار عراقي', 'minor_unit' => 0, 'symbol' => 'د.ع', 'is_default' => true, 'is_active' => true]);

        $branch = Branch::firstOrCreate(['code' => 'MAIN'], ['name' => 'الفرع الرئيسي']);

        foreach ([
            [Ledger::AR_SECONDARY, 'الديون الثانوية', 'asset'],
            [Ledger::AR_PRIMARY, 'الديون الأولية', 'asset'],
            [Ledger::CUSTOMER_CREDIT, 'دفعات مقدمة للمشتركين', 'liability'],
            [Ledger::REV_COMMISSION, 'الراجع من الشركة', 'revenue'],
            [Ledger::REV_DEVICE_SALES, 'مبيعات الأجهزة', 'revenue'],
            [Ledger::REV_OTHER, 'إيرادات أخرى', 'revenue'],
            [Ledger::EXP_GENERAL, 'المصروفات', 'expense'],
            [Ledger::EXP_PROMO_DISCOUNT, 'خصومات عروض على الوكيل', 'expense'],
            [Ledger::DISTRIBUTIONS, 'توزيعات الراجع', 'equity'],
            [Ledger::OPENING_EQUITY, 'الرصيد الابتدائي', 'equity'],
        ] as [$code, $name, $type]) {
            LedgerAccount::firstOrCreate(['code' => $code], ['name_ar' => $name, 'type' => $type, 'is_system' => true]);
        }

        $treasury = app(TreasuryService::class);
        if (! MoneyAccount::where('branch_id', $branch->id)->where('kind', MoneyAccountKind::Cash)->exists()) {
            $treasury->createMoneyAccount($branch->id, MoneyAccountKind::Cash, 'القاصة (الزون)');
        }
        if (! MoneyAccount::where('branch_id', $branch->id)->where('kind', MoneyAccountKind::Company)->exists()) {
            $treasury->createMoneyAccount($branch->id, MoneyAccountKind::Company, 'رصيد الشركة');
        }

        foreach ([['basic', 'أساسي', 35000, 1], ['plus', 'بلس', 45000, 2], ['turbo', 'تيربو', 65000, 3], ['pro_max', 'برو ماكس', 100000, 4]] as [$code, $name, $price, $order]) {
            ServicePlan::firstOrCreate(['code' => $code], ['name_ar' => $name, 'price' => $price, 'duration_days' => 30, 'sort_order' => $order]);
        }

        foreach (['رصيد الشركة', 'راوتر وأجهزة', 'كابلات ومواد', 'إيجار', 'رواتب', 'أخرى'] as $name) {
            ExpenseCategory::firstOrCreate(['name_ar' => $name]);
        }
        foreach ([['router', 'راوتر'], ['ont', 'جهاز ONT'], ['repeater', 'مقوي'], ['cable', 'كابل'], ['other', 'أخرى']] as [$key, $name]) {
            DeviceType::firstOrCreate(['key' => $key], ['name_ar' => $name]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (array_keys(Permissions::ALL) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('employee', 'web')->syncPermissions(Permissions::employeeDefaults());

        if (! User::exists()) {
            $admin = User::create([
                'branch_id' => $branch->id,
                'name' => 'المدير',
                'username' => env('ADMIN_USERNAME', 'admin'),
                'password' => env('ADMIN_PASSWORD', 'ChangeMe-2026'),
                'is_active' => true,
            ]);
            $admin->assignRole('admin');
        }
    }
}
