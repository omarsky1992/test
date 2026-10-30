<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Models\MessageTemplate;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * The fixed WhatsApp messages employees choose from when reminding subscribers.
 */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static ?string $slug = 'message-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'رسالة';

    protected static ?string $pluralModelLabel = 'قوالب الرسائل';

    public const EVENTS = ['renewal' => 'التفعيل (7 أو 30 يوماً)', 'expiring' => 'قرب انتهاء الاشتراك', 'expired' => 'انتهاء الاشتراك', 'debt' => 'تذكير بالديون', 'payment' => 'استلام دفعة'];

    public static function canViewAny(): bool
    {
        return auth()->user()->can('settings.manage');
    }

    public static function form(Schema $schema): Schema
    {
        $help = collect(MessageTemplate::PLACEHOLDERS)->map(fn ($label, $key) => "{$key} = {$label}")->implode(' · ');

        return $schema->components([
            TextInput::make('title')->label('العنوان')->required()->maxLength(80),
            TextInput::make('icon')->label('رمز (إيموجي)')->maxLength(10)->placeholder('⏳'),
            Textarea::make('body')->label('نص الرسالة')->required()->rows(6)->columnSpanFull()->helperText($help),
            Select::make('auto_event')->label('تُرسل تلقائياً عند')->live()->placeholder('لا (يدوي فقط)')
                ->options(self::EVENTS)->unique(ignoreRecord: true)
                ->helperText('تشغيل الرسائل التلقائية وإيقافها من «إعدادات واتساب».'),
            Select::make('media_type')->label('شكل الرسالة')->default('text')->required()->live()
                ->options(fn (Get $get) => MessageTemplate::MEDIA_TYPES + ($get('auto_event') === 'payment' ? MessageTemplate::RECEIPT_TYPES : []))
                ->helperText('الصورة أو PDF يُرسلان والنص تحتهما. لرسالة الدفعة يمكن إرسال الوصل نفسه صورة أو PDF. الربط المجاني بالـ QR يرسل النص مع رابط للملف.'),
            FileUpload::make('media_path')->label(fn (Get $get) => $get('media_type') === 'pdf' ? 'ملف PDF' : 'الصورة')
                ->visible(fn (Get $get) => in_array($get('media_type'), ['image', 'pdf'], true))
                ->required(fn (Get $get) => in_array($get('media_type'), ['image', 'pdf'], true))
                ->acceptedFileTypes(fn (Get $get) => $get('media_type') === 'pdf' ? ['application/pdf'] : ['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5120)->disk('local')->directory('whatsapp/templates')->visibility('private')
                ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::uuid().'/'.str_replace(['/', '\\'], '-', $file->getClientOriginalName()))
                ->storeFileNamesIn('media_name')->columnSpanFull(),
            TextInput::make('sort_order')->label('الترتيب')->integer()->default(0),
            Toggle::make('is_active')->label('فعّالة')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')->label('العنوان')->weight('bold')->formatStateUsing(fn ($state, MessageTemplate $r) => trim("{$r->icon} {$state}")),
                TextColumn::make('auto_event')->label('تلقائية')->badge()->placeholder('يدوية')->formatStateUsing(fn (?string $state) => self::EVENTS[$state] ?? $state),
                TextColumn::make('body')->label('النص')->limit(70)->wrap(),
                TextColumn::make('media_type')->label('الشكل')->badge()->formatStateUsing(fn (?string $state) => (MessageTemplate::MEDIA_TYPES + MessageTemplate::RECEIPT_TYPES)[$state] ?? $state),
                IconColumn::make('is_active')->label('فعّالة')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->after(fn (Model $record) => app(Audit::class)->log('message_template.updated', $record, null, $record->only(['title', 'body', 'is_active']))),
                DeleteAction::make()->before(fn (Model $record) => app(Audit::class)->log('message_template.deleted', $record, $record->only(['title', 'body']), null)),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->after(fn (Model $record) => app(Audit::class)->log('message_template.created', $record, null, $record->only(['title', 'body', 'is_active'])));
    }

    public static function getPages(): array
    {
        return ['index' => ManageMessageTemplates::route('/')];
    }
}
