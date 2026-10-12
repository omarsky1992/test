<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Subscriber;
use App\Services\Settings;
use App\Support\SubscriberStatus;
use App\WhatsApp\BroadcastService;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * «الرسائل الجماعية»: the user picks subscribers (by name or by a group), writes a message, may attach
 * an image, previews it and sends it to those subscribers only.
 */
class WhatsappBroadcast extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.whatsapp-broadcast';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'الرسائل الجماعية';

    protected static ?string $slug = 'whatsapp-broadcast';

    private const SHOWN = 120;

    public const GROUPS = SubscriberStatus::FILTERS + ['notes' => 'لديهم ملاحظات', 'blacklisted' => 'القائمة السوداء'];

    public string $filter = 'all';

    public string $search = '';

    /** @var array<int, int|string> */
    public array $selected = [];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()->can('whatsapp.broadcast');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Textarea::make('body')->label('نص الرسالة')->required()->rows(5)->maxLength(3000)->live(debounce: 600)
                ->helperText('يمكن استخدام {الاسم} {الفئة} {الأيام} {المبلغ} {تاريخ_الانتهاء} {اليوزر}، تُستبدل لكل مشترك.'),
            FileUpload::make('image')->label('صورة (اختياري)')->image()->maxSize(5120)
                ->disk('local')->directory('whatsapp/broadcasts')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->helperText(BroadcastService::imageMode() === 'image'
                    ? 'تُرسل الصورة مع النص كتعليق عليها.'
                    : 'الربط الحالي (WAHA المجاني) لا يرسل الصور: تُرسل الرسالة نصاً ومعها رابط لفتح الصورة.'),
        ]);
    }

    public function updatedFilter(): void
    {
        $this->selected = [];
    }

    public function selectAll(): void
    {
        $this->selected = $this->query()->limit(BroadcastService::MAX_RECIPIENTS)->pluck('accounts.id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function send(): void
    {
        $data = $this->form->getState();
        $accounts = Account::with(['subscriber', 'currentPlan'])->whereIn('id', array_map('intval', $this->selected))->get();
        $path = $data['image'] ?? null;
        $mime = $path ? Storage::disk('local')->mimeType($path) : null;

        try {
            $broadcast = app(BroadcastService::class)->send($accounts, (string) ($data['body'] ?? ''), $path, $mime ?: null);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->selected = [];
        $this->form->fill();
        Notification::make()->success()->title("أُضيفت الرسالة إلى قائمة الإرسال لـ {$broadcast->recipients} مشترك")
            ->body(($broadcast->skipped ? "تُرك {$broadcast->skipped} بلا رقم هاتف. " : '').'تُرسل تباعاً، والنتائج في «سجل الرسائل الجماعية».')->send();
    }

    private function query(): Builder
    {
        $query = SubscriberStatus::base()->with(['subscriber', 'currentPlan']);
        $query = match ($this->filter) {
            'notes' => $query->whereHas('subscriber.notes', fn (Builder $n) => $n->where('status', 'active')),
            'blacklisted' => $query->whereHas('subscriber.blacklistEntries', fn (Builder $b) => $b->whereNull('removed_at')),
            default => SubscriberStatus::apply($query, array_key_exists($this->filter, SubscriberStatus::FILTERS) ? $this->filter : 'all'),
        };
        if (filled($this->search)) {
            $query->whereIn('accounts.subscriber_id', Subscriber::query()->search($this->search)->select('subscribers.id'));
        }

        return $query->orderBy('accounts.id');
    }

    /**
     * @return Collection<int, Account>
     */
    private function chosen(): Collection
    {
        return Account::with(['subscriber', 'currentPlan'])->whereIn('id', array_map('intval', $this->selected))->get();
    }

    protected function getViewData(): array
    {
        $chosen = $this->chosen();
        $first = $chosen->first();
        $body = (string) ($this->data['body'] ?? '');

        return [
            'accounts' => $this->query()->limit(self::SHOWN)->get(),
            'shown' => self::SHOWN,
            'groups' => self::GROUPS,
            'count' => $chosen->count(),
            'withPhone' => $chosen->filter(fn (Account $a) => WhatsappReminders::phone($a))->count(),
            'preview' => $first && $body !== '' ? strtr($body, WhatsappReminders::values($first)) : null,
            'previewName' => $first?->subscriber?->full_name,
            'imageMode' => BroadcastService::imageMode(),
            'dailyLimit' => (int) app(Settings::class)->get('whatsapp.daily_limit'),
        ];
    }
}
