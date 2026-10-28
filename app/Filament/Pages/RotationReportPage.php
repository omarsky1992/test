<?php

namespace App\Filament\Pages;

use App\Models\WhatsappNumber;
use App\Services\RotationReport;
use App\Services\Settings;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «تقرير الدوّار»: primary debts + cards + custody + advances + «الربح والخسارة», with the cash and the company page
 * balance typed in when there are any, all added up; and a button to send it to the authorized numbers.
 */
class RotationReportPage extends Page
{
    protected string $view = 'filament.pages.rotation-report';

    protected static ?string $slug = 'rotation-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير الدوّار';

    protected static ?string $title = 'تقرير الدوّار';

    /** Typed by hand when there is any (whole dinars). */
    public ?string $cash = null;

    public ?string $pageBalance = null;

    /** «مقدار الدوّار»: what the total should be; kept so it need not be typed every time. */
    public ?string $target = null;

    public function mount(): void
    {
        $saved = app(Settings::class)->get('reports.rotation_target');
        $this->target = $saved !== null ? (string) $saved : null;
    }

    public function updatedTarget(): void
    {
        app(Settings::class)->set('reports.rotation_target', $this->amount($this->target));
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('reports.rotation');
    }

    private function amount(?string $value): ?int
    {
        // Arabic-Indic digits typed on a phone count too: ٢٥٠٠٠٠ = 250000.
        $value = strtr((string) $value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $digits = preg_replace('/[^\d-]/', '', $value);

        return $digits === '' || $digits === '-' ? null : (int) $digits;
    }

    public function report(): array
    {
        return app(RotationReport::class)->compute($this->amount($this->cash), $this->amount($this->pageBalance), $this->amount($this->target));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendWhatsapp')->label('إرسال للواتساب')->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                ->visible(fn () => WhatsappNumber::where('is_active', true)->exists())
                ->modalHeading('إرسال تقرير الدوّار')
                ->modalDescription(fn () => 'المجموع: '.Money::format($this->report()['total']))
                ->schema([
                    Select::make('numbers')->label('يصل إلى')->multiple()->placeholder('كل الأرقام المصرح لها')
                        ->helperText('اختر رقماً أو أكثر، أو اتركه فارغاً ليصل لكل الأرقام المصرح لها.')
                        ->options(fn () => WhatsappNumber::with('user')->where('is_active', true)->get()
                            ->mapWithKeys(fn (WhatsappNumber $n) => [$n->id => trim(($n->label ?: $n->user?->name ?: 'رقم').' – '.$n->phone)])->all()),
                ])
                ->modalSubmitActionLabel('إرسال')
                ->action(function (array $data) {
                    $count = app(RotationReport::class)->send($this->report(), $data['numbers'] ?? null);
                    Notification::make()->success()->title("أُرسل التقرير إلى {$count} رقم")->body('يصل خلال دقيقة من رقم الواتساب الرئيسي.')->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return ['report' => $this->report()];
    }
}
