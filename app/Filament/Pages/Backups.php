<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Models\BackupRun;
use App\Services\Audit;
use App\Services\BackupService;
use App\Services\GoogleDrive;
use App\Services\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Backups extends Page
{
    protected string $view = 'filament.pages.backups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'النسخ الاحتياطي';

    protected static ?string $slug = 'backups';

    public static function canAccess(): bool
    {
        return auth()->user()->can('settings.manage');
    }

    protected function getHeaderActions(): array
    {
        $drive = app(GoogleDrive::class);

        return [
            Action::make('download')
                ->label('تنزيل نسخة الآن')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->url(route('backup.download')),
            Action::make('connect')
                ->label(fn () => $drive->isConnected() ? 'تغيير حساب Google Drive' : 'ربط Google Drive')
                ->icon(Heroicon::OutlinedLink)
                ->color($drive->isConnected() ? 'gray' : 'primary')
                ->disabled(! $drive->isConfigured())
                ->fillForm(fn () => ['email' => app(Settings::class)->get('backup.google.email')])
                ->schema([
                    TextInput::make('email')->label('إيميل Google Drive')->email()->required()
                        ->helperText('بعد الحفظ تفتح صفحة Google: اختر نفس الحساب واضغط «سماح». مرة واحدة فقط.'),
                ])
                ->modalSubmitActionLabel('متابعة إلى Google')
                ->action(function (array $data) {
                    app(Settings::class)->set('backup.google.email', strtolower(trim($data['email'])));
                    $this->redirect(route('backup.google.connect'));
                }),
            Action::make('runNow')
                ->label('نسخ الآن')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->color('success')
                ->disabled(! $drive->isConnected())
                ->requiresConfirmation()
                ->modalDescription('تُنشأ نسخة كاملة من قاعدة البيانات وتُرفع إلى Google Drive الآن.')
                ->action(function () {
                    // A large dump and upload can take minutes; don't let PHP's default 30 seconds cut it.
                    @set_time_limit(900);
                    try {
                        $run = app(BackupService::class)->run('web');
                    } catch (BusinessRuleException $e) {
                        Notification::make()->warning()->title($e->getMessage())->send();

                        return;
                    }
                    $run->status === 'success'
                        ? Notification::make()->success()->title("تم رفع النسخة {$run->file_name}")->send()
                        : Notification::make()->danger()->title('فشل النسخ الاحتياطي')->body($run->error)->persistent()->send();
                }),
            Action::make('disconnect')
                ->label('فصل الربط')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible($drive->isConnected())
                ->requiresConfirmation()
                ->modalDescription('ستتوقف النسخ اليومية حتى تربط الحساب مرة أخرى. النسخ الموجودة في Drive تبقى.')
                ->action(function () use ($drive) {
                    $email = $drive->connectedEmail();
                    $drive->disconnect();
                    app(Audit::class)->log('backup.drive_disconnected', 'settings', ['email' => $email]);
                    Notification::make()->success()->title('تم فصل Google Drive')->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $drive = app(GoogleDrive::class);

        return [
            'configured' => $drive->isConfigured(),
            'connected' => $drive->isConnected(),
            'email' => $drive->connectedEmail() ?? app(Settings::class)->get('backup.google.email'),
            'redirectUri' => $drive->redirectUri(),
            'last' => app(BackupService::class)->lastSuccess(),
            'runs' => BackupRun::with('user')->latest('started_at')->limit(30)->get(),
            'keepDays' => config('backup.keep_days'),
            'serverBackups' => array_slice(app(BackupService::class)->serverBackups(), 0, 40),
        ];
    }
}
