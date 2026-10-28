<?php

namespace Tests\Feature;

use App\Filament\Pages\WhatsappSettings;
use App\Models\ExpenseCategory;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Services\Settings;
use App\Services\TreasuryService;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseAlertsTest extends TestCase
{
    private function numbers(): array
    {
        return [
            WhatsappNumber::create(['phone' => '9647701111111', 'label' => 'علي', 'user_id' => $this->admin->id, 'is_active' => true]),
            WhatsappNumber::create(['phone' => '9647702222222', 'label' => 'عمر', 'user_id' => $this->admin->id, 'is_active' => true]),
        ];
    }

    public function test_every_new_purchase_reaches_the_chosen_numbers_with_the_running_total(): void
    {
        [$ali] = $this->numbers();
        $treasury = app(TreasuryService::class);
        $treasury->openingBalance($this->cash(), 100000);

        // Off by default: nothing is sent.
        $treasury->recordExpense(ExpenseCategory::first(), 'كابل', 5000, $this->cash());
        $this->assertSame(0, WhatsappOutbox::count());

        Livewire::test(WhatsappSettings::class)
            ->fillForm(['alert_expenses' => true, 'alert_expenses_numbers' => [(string) $ali->id]])
            ->call('save')->assertNotified('تم الحفظ');
        $this->assertSame([$ali->id], app(Settings::class)->get('alerts.expenses_numbers'));

        $treasury->recordExpense(ExpenseCategory::first(), 'راوتر TP-Link', 20000, $this->cash());
        $sent = WhatsappOutbox::sole();
        $this->assertSame([$ali->phone, 'alert_expense'], [$sent->to_phone, $sent->kind]);
        $this->assertStringContainsString('راوتر TP-Link', $sent->body);
        $this->assertStringContainsString('20,000', $sent->body);
        // The running total since the last reset: 5,000 + 20,000.
        $this->assertStringContainsString('25,000', $sent->body);
    }

    public function test_no_number_chosen_means_all_and_turning_it_off_stops_it(): void
    {
        $this->numbers();
        $settings = app(Settings::class);
        $settings->set('alerts.expenses', true);
        $treasury = app(TreasuryService::class);
        $treasury->openingBalance($this->cash(), 100000);

        $treasury->recordExpense(ExpenseCategory::first(), 'شريط', 1000, $this->cash());
        $this->assertSame(2, WhatsappOutbox::count());

        $settings->set('alerts.expenses', false);
        $treasury->recordExpense(ExpenseCategory::first(), 'شريط', 1000, $this->cash());
        $this->assertSame(2, WhatsappOutbox::count());
    }
}
