<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Subscriber;
use App\Models\SubscriberLocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saving, replacing and deleting a subscriber's house location and photo. Each step checks its own
 * permission here; the photo lives on the private disk and is served only to who may see it.
 */
class LocationService
{
    public const PHOTO_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const PHOTO_MAX_KB = 8192;

    public function __construct(private Audit $audit) {}

    public function for(Subscriber $subscriber): ?SubscriberLocation
    {
        return SubscriberLocation::where('subscriber_id', $subscriber->id)->first();
    }

    /**
     * Saves the coordinates the employee confirmed. Replacing a saved location needs «تعديل الموقع»
     * and the employee's explicit confirmation ($replace).
     */
    public function save(Subscriber $subscriber, float $latitude, float $longitude, ?float $accuracy, string $source, bool $replace = false): SubscriberLocation
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
            throw new BusinessRuleException('الإحداثيات غير صحيحة. حدّد الموقع من GPS أو على الخريطة.');
        }
        if (! in_array($source, ['gps', 'map'], true)) {
            throw new BusinessRuleException('مصدر الموقع غير معروف.');
        }
        $current = $this->for($subscriber);
        $replacing = $current?->hasCoordinates() ?? false;
        $this->authorize($replacing ? 'locations.update' : 'locations.create');
        if ($replacing && ! $replace) {
            throw new BusinessRuleException('للمشترك موقع محفوظ. أكّد الاستبدال أولاً.');
        }

        return DB::transaction(function () use ($subscriber, $latitude, $longitude, $accuracy, $source, $current) {
            $old = $current?->hasCoordinates() ? ['lat' => $current->latitude, 'lng' => $current->longitude, 'accuracy_m' => $current->accuracy_m] : null;
            $location = $current ?? new SubscriberLocation(['subscriber_id' => $subscriber->id]);
            $location->fill([
                'latitude' => round($latitude, 7), 'longitude' => round($longitude, 7),
                'accuracy_m' => $accuracy !== null && $accuracy >= 0 && $accuracy < 100000 ? (int) round($accuracy) : null,
                'source' => $source, 'saved_by' => Auth::id(), 'saved_at' => now(),
            ])->save();
            $this->audit->log($old ? 'location.updated' : 'location.created', $location, $old, [
                'lat' => $location->latitude, 'lng' => $location->longitude, 'accuracy_m' => $location->accuracy_m, 'source' => $source,
            ], subscriberId: $subscriber->id);

            return $location;
        });
    }

    public function delete(Subscriber $subscriber, ?string $reason = null): void
    {
        $this->authorize('locations.delete');
        $location = $this->for($subscriber);
        if (! $location?->hasCoordinates()) {
            throw new BusinessRuleException('لا يوجد موقع محفوظ.');
        }
        DB::transaction(function () use ($location, $subscriber, $reason) {
            $old = ['lat' => $location->latitude, 'lng' => $location->longitude];
            $location->update(['latitude' => null, 'longitude' => null, 'accuracy_m' => null, 'source' => null, 'saved_by' => Auth::id(), 'saved_at' => now()]);
            $this->audit->log('location.deleted', $location, $old, null, $reason, $subscriber->id);
            if ($location->photo_path === null) {
                $location->delete();
            }
        });
    }

    /** Adds the house photo, or replaces it (that also needs «حذف أو استبدال الصورة»). */
    public function setPhoto(Subscriber $subscriber, UploadedFile $file): SubscriberLocation
    {
        $this->authorize('photos.add');
        $mime = (string) $file->getMimeType();
        if (! array_key_exists($mime, self::PHOTO_MIMES)) {
            throw new BusinessRuleException('الصورة يجب أن تكون JPG أو PNG أو WEBP.');
        }
        if ($file->getSize() > self::PHOTO_MAX_KB * 1024) {
            throw new BusinessRuleException('حجم الصورة أكبر من 8 ميغابايت.');
        }
        $current = $this->for($subscriber);
        if ($current?->photo_path) {
            $this->authorize('photos.delete');
        }
        $path = $file->storeAs("house-photos/{$subscriber->id}", Str::uuid().'.'.self::PHOTO_MIMES[$mime], 'local');

        return DB::transaction(function () use ($subscriber, $current, $path, $mime) {
            $oldPath = $current?->photo_path;
            $location = $current ?? new SubscriberLocation(['subscriber_id' => $subscriber->id]);
            $location->fill(['photo_path' => $path, 'photo_mime' => $mime, 'photo_by' => Auth::id(), 'photo_at' => now()])->save();
            $this->audit->log($oldPath ? 'location.photo_replaced' : 'location.photo_added', $location, null, ['photo' => basename($path)], subscriberId: $subscriber->id);
            if ($oldPath) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
            }

            return $location;
        });
    }

    public function deletePhoto(Subscriber $subscriber, ?string $reason = null): void
    {
        $this->authorize('photos.delete');
        $location = $this->for($subscriber);
        if (! $location?->photo_path) {
            throw new BusinessRuleException('لا توجد صورة.');
        }
        DB::transaction(function () use ($location, $subscriber, $reason) {
            $path = $location->photo_path;
            $location->update(['photo_path' => null, 'photo_mime' => null, 'photo_by' => Auth::id(), 'photo_at' => now()]);
            $this->audit->log('location.photo_deleted', $location, ['photo' => basename($path)], null, $reason, $subscriber->id);
            if (! $location->hasCoordinates()) {
                $location->delete();
            }
            DB::afterCommit(fn () => Storage::disk('local')->delete($path));
        });
    }

    private function authorize(string $permission): void
    {
        if (! Auth::user()?->can($permission)) {
            throw new AuthorizationException('ليست لديك صلاحية لهذا الإجراء.');
        }
    }
}
