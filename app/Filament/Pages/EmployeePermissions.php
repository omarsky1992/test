<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «إدارة صلاحيات الموظفين»: pick an employee and tick exactly what they may see and do, section by
 * section. A screen whose «view» is off disappears from their menu; an action that is off loses its
 * button and is refused by the server too.
 */
class EmployeePermissions extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.employee-permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'إدارة صلاحيات الموظفين';

    protected static ?string $slug = 'employee-permissions';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()->can('users.manage');
    }

    public function mount(): void
    {
        $this->form->fill(['user_id' => request()->integer('user') ?: null]);
        if ($this->data['user_id'] ?? null) {
            $this->loadUser((int) $this->data['user_id']);
        }
    }

    public static function key(string $group): string
    {
        return 'g_'.substr(md5($group), 0, 8);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (Permissions::GROUPS as $label => $names) {
            $sections[] = Section::make($label)->collapsible()->compact()
                ->visible(fn (Get $get) => filled($get('user_id')))
                ->schema([
                    CheckboxList::make(self::key($label))->hiddenLabel()->columns(['default' => 1, 'md' => 2])->bulkToggleable()
                        ->options(collect($names)->mapWithKeys(fn ($p) => [$p => Permissions::ALL[$p][0]])->all()),
                ]);
        }

        return $schema->statePath('data')->components([
            Select::make('user_id')->label('الموظف')->searchable()->live()->required()
                ->options(fn () => User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'))
                    ->whereKeyNot(auth()->id())->orderBy('name')->pluck('name', 'id'))
                ->afterStateUpdated(fn ($state) => $state ? $this->loadUser((int) $state) : null)
                ->helperText('المدير يملك كل الصلاحيات دائماً، ولا يستطيع أي مستخدم تعديل صلاحياته بنفسه.'),
            ...$sections,
            Actions::make([
                Action::make('save')->label('حفظ الصلاحيات')->icon(Heroicon::OutlinedCheck)->action('save'),
                Action::make('defaults')->label('إرجاع صلاحيات الدور الافتراضية')->color('gray')
                    ->requiresConfirmation()->action('resetToDefaults'),
            ])->visible(fn (Get $get) => filled($get('user_id'))),
        ]);
    }

    private function loadUser(int $id): void
    {
        $user = User::find($id);
        if (! $user) {
            return;
        }
        $effective = app(PermissionService::class)->effective($user);
        $state = ['user_id' => $id];
        foreach (Permissions::GROUPS as $label => $names) {
            $state[self::key($label)] = array_values(array_intersect($names, $effective));
        }
        $this->form->fill($state);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = User::findOrFail($data['user_id']);
        $chosen = [];
        foreach (Permissions::GROUPS as $label => $names) {
            $chosen = array_merge($chosen, array_intersect($names, $data[self::key($label)] ?? []));
        }
        try {
            app(PermissionService::class)->apply($user, $chosen);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->loadUser($user->id);
        Notification::make()->success()->title("حُفظت صلاحيات {$user->name}")->body('تُطبَّق فوراً، وتظهر له عند تحديث الصفحة.')->send();
    }

    public function resetToDefaults(): void
    {
        $user = User::findOrFail($this->data['user_id']);
        try {
            app(PermissionService::class)->reset($user);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->loadUser($user->id);
        Notification::make()->success()->title('أُرجعت صلاحيات الدور الافتراضية')->send();
    }
}
