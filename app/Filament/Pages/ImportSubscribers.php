<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Imports\SpreadsheetReader;
use App\Imports\SubscriberImporter;
use App\Models\ImportRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Import subscribers from Excel in three steps: upload, map columns and choose options, preview and confirm.
 */
class ImportSubscribers extends Page
{
    protected string $view = 'filament.pages.import-subscribers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'استيراد المشتركين';

    protected static ?string $title = 'استيراد المشتركين من Excel';

    protected static ?string $slug = 'import-subscribers';

    public int $step = 1;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?string $filePath = null;

    public ?string $originalName = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, string> first data row, for showing sample values */
    public array $sample = [];

    public int $rowCount = 0;

    public ?array $stats = null;

    public array $previewRows = [];

    public ?int $runId = null;

    public static function canAccess(): bool
    {
        return auth()->user()->can('subscribers.create');
    }

    public function mount(): void
    {
        $this->form->fill(['mode' => SubscriberImporter::MODE_SKIP, 'update_company_data' => false, 'date_format' => 'auto']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('1. رفع الملف')
                ->description('ملف Excel (‎.xlsx‎) أو CSV. ملف التصدير من موقع الشركة يُرفع كما هو.')
                ->visible(fn () => $this->step === 1)
                ->schema([
                    FileUpload::make('file')
                        ->label('الملف')
                        ->disk('local')
                        ->directory('imports')
                        ->visibility('private')
                        ->storeFileNamesIn('original_name')
                        ->acceptedFileTypes([
                            'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->maxSize(20480)
                        ->required(),
                ]),
            Section::make('2. مطابقة الأعمدة')
                ->description('اختر أي عمود في الملف يقابل كل حقل. تمت المطابقة تلقائياً حسب أسماء الأعمدة؛ راجعها.')
                ->visible(fn () => $this->step === 2)
                ->columns(3)
                ->schema(fn () => collect(SubscriberImporter::FIELDS)->map(fn ($field, $key) => Select::make("mapping.{$key}")
                    ->label($field[0].(in_array($key, ['full_name'], true) ? ' *' : ''))
                    ->options($this->columnOptions())
                    ->placeholder('— لا يوجد —')
                    ->helperText(fn ($state) => $state !== null && $state !== '' && isset($this->sample[(int) $state]) && $this->sample[(int) $state] !== ''
                        ? 'مثال: '.mb_strimwidth($this->sample[(int) $state], 0, 40, '…') : null))->values()->all()),
            Section::make('خيارات الاستيراد')
                ->visible(fn () => $this->step === 2)
                ->schema([
                    Radio::make('mode')
                        ->label('المشتركون والحسابات الموجودون مسبقاً في النظام')
                        ->options([
                            SubscriberImporter::MODE_SKIP => 'لا تغيّر شيئاً فيهم (الأكثر أماناً): يُضاف الجديد فقط',
                            SubscriberImporter::MODE_FILL => 'أكمل الحقول الفارغة فقط، ولا تغيّر أي قيمة موجودة',
                            SubscriberImporter::MODE_OVERWRITE => 'استبدل قيمهم بقيم الملف (الحقول غير الفارغة في الملف فقط)',
                        ])
                        ->required(),
                    Grid::make(2)->schema([
                        Toggle::make('update_company_data')
                            ->label('حدّث بيانات الشركة للحسابات الموجودة: الحالة، تاريخ الانتهاء، الفئة')
                            ->helperText('للحسابات الجديدة تُحفظ هذه البيانات دائماً. لا يُنشئ الاستيراد ديوناً ولا تفعيلات.'),
                        Select::make('date_format')
                            ->label('صيغة التاريخ في الملف')
                            ->options(['auto' => 'تلقائي', 'mdy' => 'شهر/يوم/سنة (4/9/2026 = 9 نيسان)', 'dmy' => 'يوم/شهر/سنة (4/9/2026 = 4 أيلول)'])
                            ->required(),
                    ]),
                ]),
        ]);
    }

    public function templateAction(): Action
    {
        return Action::make('template')
            ->label('تنزيل قالب Excel جاهز')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->url(route('import.subscribers.template'));
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('متابعة')
            ->icon(Heroicon::OutlinedArrowLeft)
            ->action(function () {
                $state = $this->form->getState();
                $this->filePath = is_array($state['file']) ? array_values($state['file'])[0] : $state['file'];
                $names = $state['original_name'] ?? null;
                $this->originalName = is_array($names) ? (array_values($names)[0] ?? basename($this->filePath)) : ($names ?: basename($this->filePath));

                try {
                    $sheet = app(SpreadsheetReader::class)->read($this->absolutePath(), $this->originalName);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                $this->headers = $sheet['headers'];
                $this->sample = $sheet['rows'][0]['cells'] ?? [];
                $this->rowCount = count($sheet['rows']);
                $this->data['mapping'] = app(SubscriberImporter::class)->suggestMapping($this->headers);
                $this->step = 2;
            });
    }

    public function previewAction(): Action
    {
        return Action::make('preview')
            ->label('معاينة')
            ->icon(Heroicon::OutlinedEye)
            ->action(function () {
                $this->form->getState();
                if (($this->data['mapping']['full_name'] ?? null) === null || ($this->data['mapping']['external_id'] ?? null) === null && ($this->data['mapping']['phone'] ?? null) === null) {
                    Notification::make()->danger()->title('حدّد عمود الاسم، وعمود رقم المشترك أو رقم الهاتف على الأقل.')->send();

                    return;
                }
                try {
                    $plan = app(SubscriberImporter::class)->analyze($this->absolutePath(), $this->originalName, $this->mapping(), $this->options());
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                $this->stats = $plan->stats;
                // Problems first, then the rest; the page shows up to 500 rows.
                $order = ['error' => 0, 'create' => 2, 'update' => 1, 'merged' => 3, 'same' => 4];
                $this->previewRows = collect($plan->rows)
                    ->sortBy(fn ($r) => [$order[$r['status']] ?? 9, $r['messages'] === [] ? 1 : 0, $r['line']])
                    ->take(500)->values()->all();
                $this->step = 3;
            });
    }

    public function backAction(): Action
    {
        return Action::make('back')
            ->label('رجوع')
            ->color('gray')
            ->action(fn () => $this->step = max(1, $this->step - 1));
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label(fn () => 'تنفيذ الاستيراد')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->disabled(fn () => ! $this->stats || ($this->stats['subscribers_create'] + $this->stats['subscribers_update'] + $this->stats['accounts_create'] + $this->stats['accounts_update']) === 0)
            ->requiresConfirmation()
            ->modalHeading('تأكيد الاستيراد')
            ->modalDescription(fn () => $this->confirmationText())
            ->modalSubmitActionLabel('نعم، نفّذ الاستيراد')
            ->action(function () {
                try {
                    $run = app(SubscriberImporter::class)->execute($this->absolutePath(), $this->originalName, $this->mapping(), $this->options());
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('فشل الاستيراد ولم يُحفظ أي شيء')->body(mb_strimwidth($e->getMessage(), 0, 300, '…'))->persistent()->send();

                    return;
                }
                Storage::disk('local')->delete($this->filePath);
                $this->runId = $run->id;
                $this->stats = $run->stats;
                $this->step = 4;
                Notification::make()->success()->title('تم الاستيراد')->send();
            });
    }

    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label('استيراد ملف آخر')
            ->color('gray')
            ->action(function () {
                $this->reset(['step', 'filePath', 'originalName', 'headers', 'sample', 'rowCount', 'stats', 'previewRows', 'runId']);
                $this->form->fill(['mode' => SubscriberImporter::MODE_SKIP, 'update_company_data' => false, 'date_format' => 'auto']);
            });
    }

    public function subscribersUrl(): string
    {
        return SubscriberResource::getUrl('index');
    }

    public function recentRuns()
    {
        return ImportRun::with('creator')->latest('id')->limit(5)->get();
    }

    private function confirmationText(): string
    {
        $s = $this->stats ?? [];
        $text = "سيُضاف {$s['subscribers_create']} مشترك و{$s['accounts_create']} حساب";
        if (($s['subscribers_update'] ?? 0) + ($s['accounts_update'] ?? 0) > 0) {
            $text .= "، ويُحدَّث {$s['subscribers_update']} مشترك و{$s['accounts_update']} حساب";
        }
        $text .= '. الصفوف التي فيها أخطاء ('.($s['rows_error'] ?? 0).') لن تُستورد. لا يُحذف أي شيء.';
        if (($this->data['mode'] ?? null) === SubscriberImporter::MODE_OVERWRITE) {
            $text .= ' ⚠️ اخترت استبدال القيم الموجودة بقيم الملف.';
        }

        return $text;
    }

    /**
     * @return array<string, int|null>
     */
    private function mapping(): array
    {
        return collect($this->data['mapping'] ?? [])->map(fn ($v) => $v === null || $v === '' ? null : (int) $v)->all();
    }

    private function options(): array
    {
        return [
            'mode' => $this->data['mode'] ?? SubscriberImporter::MODE_SKIP,
            'update_company_data' => (bool) ($this->data['update_company_data'] ?? false),
            'date_format' => $this->data['date_format'] ?? 'auto',
        ];
    }

    private function columnOptions(): array
    {
        $letters = fn (int $i) => $i < 26 ? chr(65 + $i) : chr(64 + intdiv($i, 26)).chr(65 + $i % 26);

        return collect($this->headers)->mapWithKeys(fn ($h, $i) => [$i => $letters($i).': '.($h !== '' ? $h : '(بدون عنوان)')])->all();
    }

    private function absolutePath(): string
    {
        return Storage::disk('local')->path($this->filePath);
    }
}
