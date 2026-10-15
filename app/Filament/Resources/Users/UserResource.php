<?php

namespace App\Filament\Resources\Users;

use App\Filament\Pages\EmployeePermissions;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Branch;
use App\Models\User;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون والصلاحيات';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('users.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(120),
                TextInput::make('username')->label('اسم المستخدم')->required()->maxLength(60)->unique(ignoreRecord: true)
                    ->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('phone')->label('الهاتف')->tel(),
                Select::make('branch_id')->label('الفرع')->options(fn () => Branch::pluck('name', 'id'))->required()
                    ->default(fn () => auth()->user()->branch_id),
                TextInput::make('password')->label('كلمة المرور')->password()->revealable()->minLength(8)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'اتركها فارغة للإبقاء على الحالية.' : null),
                Toggle::make('is_active')->label('فعّال')->default(true),
                Toggle::make('collects_to_custody')->label('النقد الذي يستلمه يدخل في عهدته')->default(true)
                    ->helperText('يبقى المبلغ على الموظف حتى يسلّمه للصندوق. أطفئه للمدير الذي يستلم في القاصة مباشرة.'),
                // Only the admin assigns roles: nobody else can make themselves (or anyone) an admin.
                Select::make('roles')->label('الدور')->relationship('roles', 'name')->multiple()->preload()
                    ->disabled(fn () => ! auth()->user()->isAdmin())->dehydrated(fn () => auth()->user()->isAdmin())
                    ->getOptionLabelFromRecordUsing(fn ($record) => ['admin' => 'مدير', 'employee' => 'موظف'][$record->name] ?? $record->name),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->weight('bold'),
                TextColumn::make('username')->label('اسم المستخدم')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('roles.name')->label('الدور')->badge()
                    ->formatStateUsing(fn ($state) => ['admin' => 'مدير', 'employee' => 'موظف'][$state] ?? $state),
                TextColumn::make('branch.name')->label('الفرع'),
                TextColumn::make('last_login_at')->label('آخر دخول')->dateTime('Y/m/d H:i')->placeholder('—'),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->recordActions([
                Action::make('permissions')->label('الصلاحيات')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                    ->visible(fn (User $record) => ! $record->isAdmin() && ! $record->is(auth()->user()))
                    ->url(fn (User $record) => EmployeePermissions::getUrl(['user' => $record->id])),
                EditAction::make()->using(fn (Model $record, array $data) => self::save($record, $data)),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->using(fn (array $data) => self::save(new User, $data));
    }

    private static function save(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            if ($user->exists && $user->is(auth()->user()) && (! ($data['is_active'] ?? true))) {
                throw ValidationException::withMessages(['is_active' => 'لا يمكنك تعطيل حسابك.']);
            }
            $isNew = ! $user->exists;
            $original = $user->getAttributes();
            $user->fill(collect($data)->except(['roles'])->all());
            if (filled($data['password'] ?? null)) {
                $user->password_changed_at = now();
            }
            $user->save();

            if (! $isNew && ! $user->is_active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            if (User::role('admin')->where('is_active', true)->doesntExist()) {
                throw ValidationException::withMessages(['roles' => 'يجب أن يبقى مدير فعّال واحد على الأقل.']);
            }

            app(Audit::class)->log($isNew ? 'user.created' : 'user.updated', $user, $isNew ? null : array_intersect_key($original, ['name' => 1, 'username' => 1, 'is_active' => 1]),
                ['name' => $user->name, 'username' => $user->username, 'is_active' => $user->is_active]);

            return $user;
        });
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
