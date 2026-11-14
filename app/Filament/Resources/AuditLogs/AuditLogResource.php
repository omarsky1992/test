<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditDetails;
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
        'payment.created' => 'قبض', 'payment.voided' => 'إلغاء سند', 'credit.applied' => 'استخدام رصيد مقدم', 'credit.cleared' => 'حذف رصيد مقدم', 'credit.unapplied' => 'إرجاع دين سُدد من الرصيد المقدم', 'receipt.viewed' => 'عرض سند',
        'follow_up.created' => 'نتيجة اتصال', 'expense.created' => 'مصروف', 'expense.voided' => 'إلغاء مصروف',
        'fund_transfer.created' => 'تحويل بين الصناديق', 'settlement.created' => 'راجع الشركة', 'settlement.distributed' => 'تقسيم الراجع',
        'money_account.created' => 'صندوق جديد', 'money_account.opening_balance' => 'رصيد ابتدائي',
        'plan.created' => 'فئة جديدة', 'plan.updated' => 'تعديل فئة', 'promotion.created' => 'عرض جديد', 'promotion.updated' => 'تعديل عرض',
        'backup.completed' => 'نسخة احتياطية', 'backup.downloaded' => 'تنزيل نسخة احتياطية', 'backup.failed' => 'فشل نسخة احتياطية', 'backup.drive_connected' => 'ربط Google Drive', 'backup.drive_disconnected' => 'فصل Google Drive',
        'payment_method.created' => 'طريقة دفع جديدة', 'payment_method.updated' => 'تعديل طريقة دفع',
        'subscribers.imported' => 'استيراد مشتركين', 'subscribers.import_failed' => 'فشل استيراد', 'subscriber.imported' => 'مشترك مستورد', 'account.imported' => 'حساب مستورد',
        'sale.created' => 'بيع جهاز', 'sale.voided' => 'إلغاء بيع',
        'user.created' => 'مستخدم جديد', 'user.updated' => 'تعديل مستخدم', 'settings.updated' => 'تعديل الإعدادات',
        'company.synced' => 'مزامنة موقع الشركة', 'subscriber.synced_new' => 'مشترك جديد من الموقع', 'subscriber.synced' => 'تحديث مشترك من الموقع',
        'account.synced_new' => 'حساب جديد من الموقع', 'account.synced' => 'تحديث حساب من الموقع', 'renewal.detected' => 'تجديد مكتشف', 'renewal.reclassified' => 'تصحيح تجديد إلى تفعيل',
        'settings.sync_updated' => 'إعدادات المزامنة', 'custody.settled' => 'تسديد عهدة للصندوق', 'advance.created' => 'سلفة موظف',
        'advance.repaid' => 'تسديد سلفة', 'system.reset' => 'تصفير النظام',
        'payment.box_changed' => 'تصحيح الصندوق', 'subscriber.notify_phone' => 'رقم الرسائل', 'custody.renamed' => 'تغيير اسم العهدة',
        'custody.handover_requested' => 'طلب تسليم عهدة', 'custody.handover_approved' => 'موافقة تسليم عهدة', 'custody.handover_rejected' => 'رفض تسليم عهدة',
        'fund_transfer.voided' => 'إلغاء تحويل', 'money_account.reset' => 'تصفير رصيد بطاقة', 'money_account.reset_voided' => 'إلغاء تصفير بطاقة',
        'renewal.decided' => 'قرار المفعّل حديثاً', 'renewal.payment_source' => 'نوع دفع التجديد', 'renewal.secondary_approved' => 'المكتشف 7 أيام: دين ثانوي', 'renewal.secondary_declined' => 'المكتشف 7 أيام: بدون دين',
        'activation_due.opened' => 'يجب التفعيل', 'activation_due.done' => 'تم التفعيل', 'activation_due.cancelled' => 'إلغاء يجب التفعيل',
        'activation_reminder.set' => 'تذكير تفعيل', 'activation_reminder.cancelled' => 'إلغاء تذكير تفعيل',
        'note.created' => 'ملاحظة جديدة', 'note.closed' => 'إغلاق ملاحظة', 'note.deleted' => 'حذف ملاحظة',
        'blacklist.added' => 'إضافة للقائمة السوداء', 'blacklist.removed' => 'إزالة من القائمة السوداء',
        'location.deleted' => 'حذف موقع البيت', 'location.photo_deleted' => 'حذف صورة البيت',
        'secondary_sale.created' => 'مبيعات ثانوية', 'secondary_sale.voided' => 'إلغاء مبيعات ثانوية', 'secondary_sales.reset' => 'تصفير المبيعات الثانوية',
        'stock.added' => 'إضافة كروت', 'stock.activated' => 'تفعيل كارت', 'stock.dispensed' => 'صرف كارت', 'stock.voided' => 'إلغاء كارت',
        'period.closed' => 'تصفير المبيعات/المشتريات', 'period.opened' => 'فتح فترة', 'report.rotation_sent' => 'إرسال تقرير الدوّار',
        'whatsapp.command' => 'أمر واتساب', 'whatsapp.unauthorized' => 'رقم غير مخوّل', 'whatsapp.test_sent' => 'اختبار رسالة واتساب',
        'whatsapp.broadcast' => 'رسائل جماعية', 'whatsapp.reminder' => 'تذكير واتساب', 'whatsapp.reminders_queued' => 'تذكيرات واتساب',
        'whatsapp_number.added' => 'رقم واتساب مخوّل', 'whatsapp_number.deleted' => 'حذف رقم واتساب',
        'message_template.created' => 'قالب رسالة جديد', 'message_template.updated' => 'تعديل قالب رسالة', 'message_template.deleted' => 'حذف قالب رسالة',
        'command_pattern.created' => 'صيغة أمر جديدة', 'command_pattern.updated' => 'تعديل صيغة أمر', 'command_pattern.deleted' => 'حذف صيغة أمر',
        'user.permissions_changed' => 'تعديل الصلاحيات',
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
            TextEntry::make('target')->label('إلى من')->state(fn (AuditLog $r) => AuditDetails::target($r))->placeholder('—'),
            TextEntry::make('origin')->label('من أين')->state(fn (AuditLog $r) => AuditDetails::origin($r)),
            TextEntry::make('reason')->label('السبب')->placeholder('—')->columnSpanFull(),
            KeyValueEntry::make('old_values')->label('القيمة السابقة')->placeholder('—')
                ->state(fn (AuditLog $r) => AuditDetails::details($r->old_values) ?: null)->keyLabel('الحقل')->valueLabel('القيمة'),
            KeyValueEntry::make('new_values')->label('كيف (التفاصيل)')->placeholder('—')
                ->state(fn (AuditLog $r) => AuditDetails::details($r->new_values) ?: null)->keyLabel('الحقل')->valueLabel('القيمة'),
            TextEntry::make('ip_address')->label('IP')->placeholder('—'),
            TextEntry::make('entity')->label('السجل')->state(fn (AuditLog $r) => "{$r->entity_type} #{$r->entity_id}"),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'subscriber']))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('الوقت')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('user.name')->label('المستخدم')->placeholder('النظام'),
                TextColumn::make('action')->label('العملية')->badge()->formatStateUsing(fn ($state) => self::ACTIONS[$state] ?? $state)
                    ->color(fn ($state) => str_contains($state, 'void') || str_contains($state, 'secret') || str_contains($state, 'edited') ? 'danger' : 'gray'),
                TextColumn::make('target')->label('إلى من')->placeholder('—')->wrap()
                    ->state(fn (AuditLog $r) => AuditDetails::target($r))
                    ->url(fn (AuditLog $r) => $r->subscriber_id && auth()->user()->can('subscribers.view') ? SubscriberResource::getUrl('view', ['record' => $r->subscriber_id]) : null),
                TextColumn::make('origin')->label('من أين')->wrap()->state(fn (AuditLog $r) => AuditDetails::origin($r)),
                TextColumn::make('summary')->label('كيف')->wrap()->limit(160)->state(fn (AuditLog $r) => AuditDetails::summary($r)),
                TextColumn::make('reason')->label('السبب')->placeholder('—')->limit(40),
            ])
            ->filters([
                SelectFilter::make('action')->label('العملية')->options(self::ACTIONS),
                SelectFilter::make('user_id')->label('المستخدم')->options(fn () => User::pluck('name', 'id')),
                SelectFilter::make('source')->label('من أين')->options(AuditDetails::SOURCES),
                SelectFilter::make('subscriber_id')->label('المشترك')->relationship('subscriber', 'full_name')->searchable(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
