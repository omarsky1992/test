<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Subscriber;
use App\Services\LocationService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * «موقع المشترك»: the map of the subscriber's house. On site the employee takes the phone's GPS
 * position (or taps the map), drags the marker to correct it and saves; later anyone allowed sees the
 * saved point and opens directions to it. The optional house photo is added here too.
 */
class SubscriberLocationPage extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.subscriber-location';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'subscriber-location';

    #[Url]
    public int $subscriber = 0;

    #[Url]
    public string $mode = 'view';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user->can('locations.view') || $user->can('locations.create') || $user->can('photos.view') || $user->can('photos.add');
    }

    public function mount(): void
    {
        abort_unless(Subscriber::whereKey($this->subscriber)->exists(), 404);
        if ($this->mode === 'edit' && ! $this->canEditLocation()) {
            $this->mode = 'view';
        }
    }

    public function getTitle(): string
    {
        return 'موقع المشترك: '.$this->record()->full_name;
    }

    public function record(): Subscriber
    {
        return Subscriber::with('location.saver', 'location.photographer')->findOrFail($this->subscriber);
    }

    public function canEditLocation(): bool
    {
        $hasLocation = (bool) $this->record()->location?->hasCoordinates();

        return auth()->user()->can($hasLocation ? 'locations.update' : 'locations.create');
    }

    public function edit(): void
    {
        if ($this->canEditLocation()) {
            $this->mode = 'edit';
            $this->dispatch('location-mode', mode: 'edit');
        }
    }

    public function cancelEdit(): void
    {
        $this->mode = 'view';
        $this->dispatch('location-mode', mode: 'view');
    }

    /** Called by the map once the employee confirms the point. */
    public function saveLocation(float $latitude, float $longitude, ?float $accuracy, string $source, bool $replace = false): void
    {
        try {
            $location = app(LocationService::class)->save($this->record(), $latitude, $longitude, $accuracy, $source, $replace);
        } catch (BusinessRuleException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->mode = 'view';
        $this->dispatch('location-saved', lat: $location->latitude, lng: $location->longitude, accuracy: $location->accuracy_m);
        Notification::make()->success()->title('حُفظ موقع البيت')
            ->body($location->accuracy_m ? "دقة GPS تقريباً {$location->accuracy_m} متر." : null)->send();
    }

    public function deleteLocation(): void
    {
        try {
            app(LocationService::class)->delete($this->record());
        } catch (BusinessRuleException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->mode = 'view';
        $this->dispatch('location-deleted');
        Notification::make()->success()->title('حُذف الموقع')->send();
    }

    public function savePhoto(): void
    {
        $this->validate(['photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.LocationService::PHOTO_MAX_KB]], [
            'photo.required' => 'اختر صورة أولاً.',
            'photo.mimetypes' => 'الصورة يجب أن تكون JPG أو PNG أو WEBP.',
            'photo.max' => 'حجم الصورة أكبر من 8 ميغابايت.',
        ]);
        try {
            app(LocationService::class)->setPhoto($this->record(), $this->photo);
        } catch (BusinessRuleException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->photo = null;
        Notification::make()->success()->title('حُفظت صورة البيت')->send();
    }

    public function deletePhoto(): void
    {
        try {
            app(LocationService::class)->deletePhoto($this->record());
        } catch (BusinessRuleException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title('حُذفت صورة البيت')->send();
    }

    protected function getViewData(): array
    {
        $subscriber = $this->record();
        $location = $subscriber->location;
        $user = auth()->user();
        $canSee = $user->can('locations.view');

        return [
            'subscriberModel' => $subscriber,
            'location' => $location,
            'saved' => $canSee && $location?->hasCoordinates() ? ['lat' => $location->latitude, 'lng' => $location->longitude, 'accuracy' => $location->accuracy_m] : null,
            'canSee' => $canSee,
            'canEdit' => $this->canEditLocation(),
            'canDelete' => $user->can('locations.delete') && $location?->hasCoordinates(),
            'canDirections' => $user->can('locations.directions'),
            'canSeePhoto' => $user->can('photos.view'),
            'canAddPhoto' => $user->can('photos.add') && (! $location?->photo_path || $user->can('photos.delete')),
            'canDeletePhoto' => $user->can('photos.delete'),
            'backUrl' => SubscriberResource::getUrl('view', ['record' => $subscriber]),
        ];
    }
}
