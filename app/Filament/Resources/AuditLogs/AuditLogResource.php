<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'عملية';

    protected static ?string $pluralModelLabel = 'سجل العمليات';

    public const ACTIONS = [
        'subscriber.created' => 'إنشاء مشترك', 'subscriber.updated' => 'تعديل مشترك',
        'account.created' => 'إنشاء حساب', 'account.updated' => 'تعديل حساب', 'account.secret_viewed' => 'إظهار باسورد',
        'activation.created' => 'تفعيل', 'activation.completed' => 'إكمال +23 يوم', 'activation.start_edited' => 'تعديل وقت التفعيل', 'activation.voided' => 'إلغاء تفعيل',
        'debt.created' => 'إنشاء دين', 'debt.voided' => 'حذف دين', 'transfer.created' => 'مناقلة',
        'payment.created' => 'قبض', 'payment.voided' => 'إلغاء سند', 'credit.applied' => 'استخدام رصيد مقدم', 'receipt.viewed' => 'عرض سند',
        'follow_up.created' => 'نتيجة اتصال', 'expense.created' => 'مصروف', 'expense.voided' => 'إلغاء مصروف',
        'fund_transfer.created' => 'تحويل بين الصناديق', 'settlement.created' => 'راجع الشركة', 'settlement.distributed' => 'تقسيم الراجع',
        'money_account.created' => 'صندوق جديد', 'money_account.opening_balance' => 'رصيد ابتدائي',
        'plan.created' => 'فئة جديدة', 'plan.updated' => 'تعديل فئة', 'promotion.created' => 'عرض جديد', 'promotion.updated' => 'تعديل عرض',
        'backup.completed' => 'نسخة احتياطية', 'backup.failed' => 'فشل نسخة احتياطية', 'backup.drive_connected' => 'ربط Google Drive', 'backup.drive_disconnected' => 'فصل Google Drive',
        'payment_method.created' => 'طريقة دفع جديدة', 'payment_method.updated' => 'تعديل طريقة دفع',
        'subscribers.imported' => 'استيراد مشتركين', 'subscribers.import_failed' => 'فشل استيراد', 'subscriber.imported' => 'مشترك مستورد', 'account.imported' => 'حساب مستورد',
        'sale.created' => 'بيع جهاز', 'sale.voided' => 'إلغاء بيع',
        'user.created' => 'مستخدم جديد', 'user.updated' => 'تعديل مستخدم', 'settings.updated' => 'تعديل الإعدادات',
        'company.synced' => 'مزامنة موقع الشركة', 'subscriber.synced_new' => 'مشترك جديد من الموقع', 'subscriber.synced' => 'تحديث مشترك من الموقع',
        'account.synced_new' => 'حساب جديد من الموقع', 'account.synced' => 'تحديث حساب من الموقع', 'renewal.detected' => 'تجديد مكتشف', 'renewal.reclassified' => 'تصحيح تجديد إلى تفعيل',
        'settings.sync_updated' => 'إعدادات المزامنة', 'custody.settled' => 'تسديد عهدة للصندوق', 'advance.created' => 'سلفة موظف',
        'advance.repaid' => 'تسديد سلفة', 'system.reset' => 'تصفير النظام',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()->can('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('occurred_at')->label('الوقت')->dateTime('Y/m/d H:i:s'),
            TextEntry::make('user.name')->label('المستخدم')->placeholder('النظام'),
            TextEntry::make('action')->label('العملية')->formatStateUsing(fn ($state) => self::ACTIONS[$state] ?? $state),
            TextEntry::make('entity')->label('السجل')->state(fn (AuditLog $r) => "{$r->entity_type} #{$r->entity_id}"),
            TextEntry::make('reason')->label('السبب')->placeholder('—')->columnSpanFull(),
            KeyValueEntry::make('old_values')->label('القيمة السابقة')->placeholder('—'),
            KeyValueEntry::make('new_values')->label('القيمة الجديدة')->placeholder('—'),
            TextEntry::make('ip_address')->label('IP')->placeholder('—'),
            TextEntry::make('source')->label('المصدر'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('user.name')->label('المستخدم')->placeholder('النظام'),
                TextColumn::make('action')->label('العملية')->badge()->formatStateUsing(fn ($state) => self::ACTIONS[$state] ?? $state)
                    ->color(fn ($state) => str_contains($state, 'void') || str_contains($state, 'secret') || str_contains($state, 'edited') ? 'danger' : 'gray'),
                TextColumn::make('summary')->label('التفاصيل')->wrap()->limit(80)
                    ->state(fn (AuditLog $r) => collect($r->new_values ?? [])->map(fn ($v, $k) => $k.': '.(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v))->join(' · ')),
                TextColumn::make('reason')->label('السبب')->placeholder('—')->limit(40),
            ])
            ->filters([
                SelectFilter::make('action')->label('العملية')->options(self::ACTIONS),
                SelectFilter::make('user_id')->label('المستخدم')->options(fn () => User::pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
