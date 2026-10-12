<?php

namespace App\Support;

use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\BlacklistEntry;
use App\Models\SubscriberNote;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/**
 * Which subscribers have active notes («وضع المشترك») or are on the blacklist, read once per request
 * and forgotten whenever a note or an entry changes. Used to mark their names in every list.
 */
class SubscriberFlags
{
    /** @var array<int, int>|null subscriber id => active notes */
    private static ?array $notes = null;

    /** @var array<int, true>|null */
    private static ?array $blacklisted = null;

    public static function activeNotes(?int $subscriberId): int
    {
        self::$notes ??= SubscriberNote::active()->selectRaw('subscriber_id, count(*) as c')->groupBy('subscriber_id')
            ->pluck('c', 'subscriber_id')->map(fn ($c) => (int) $c)->all();

        return $subscriberId ? (self::$notes[$subscriberId] ?? 0) : 0;
    }

    public static function hasNotes(?int $subscriberId): bool
    {
        return self::activeNotes($subscriberId) > 0;
    }

    public static function blacklisted(?int $subscriberId): bool
    {
        self::$blacklisted ??= array_fill_keys(BlacklistEntry::current()->pluck('subscriber_id')->all(), true);

        return $subscriberId !== null && isset(self::$blacklisted[$subscriberId]);
    }

    public static function forget(): void
    {
        self::$notes = null;
        self::$blacklisted = null;
    }

    /** The notes page of a subscriber (the «وضع المشترك» tab of its details). */
    public static function notesUrl(int $subscriberId): string
    {
        return SubscriberResource::getUrl('view', ['record' => $subscriberId, 'relation' => 'notes']);
    }

    /** A subscriber's page; its notes tab when it has active notes. */
    public static function subscriberUrl(?int $subscriberId): ?string
    {
        if (! $subscriberId) {
            return null;
        }

        return self::hasNotes($subscriberId) ? self::notesUrl($subscriberId)
            : SubscriberResource::getUrl('view', ['record' => $subscriberId]);
    }

    /**
     * Marks a subscriber's name in a table: a note icon when it has active notes (the name then
     * opens its notes), «⛔» in the tooltip when it is on the blacklist.
     */
    public static function mark(TextColumn $column, \Closure $subscriberId): TextColumn
    {
        return $column
            ->icon(fn ($record) => self::hasNotes($subscriberId($record)) ? Heroicon::ChatBubbleBottomCenterText : (self::blacklisted($subscriberId($record)) ? Heroicon::NoSymbol : null))
            ->iconColor(fn ($record) => self::hasNotes($subscriberId($record)) ? 'warning' : 'danger')
            ->iconPosition('after')
            ->tooltip(fn ($record) => self::tooltip($subscriberId($record)));
    }

    public static function tooltip(?int $subscriberId): ?string
    {
        $parts = array_filter([
            ($n = self::activeNotes($subscriberId)) > 0 ? "لديه {$n} ملاحظة نشطة – اضغط لفتح وضع المشترك" : null,
            self::blacklisted($subscriberId) ? 'في القائمة السوداء' : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** The marks as text, for the Blade views (cards, lists). */
    public static function badges(?int $subscriberId): string
    {
        return (self::hasNotes($subscriberId) ? '📝' : '').(self::blacklisted($subscriberId) ? '⛔' : '');
    }
}
