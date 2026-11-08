<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «قياس المسار»: a field tool for the cable length. The technician stands by the pole and drops a
 * point (from the phone's GPS or a tap on the map), walks to the subscriber's house and drops the
 * next, then reads the route length in metres to pick the cable. Several routes can be measured and
 * compared, and every point shows its latitude/longitude to send to the company. A tool only — it
 * reads and writes nothing in the system (no subscriber, money or database).
 */
class RouteMeasure extends Page
{
    protected string $view = 'filament.pages.route-measure';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'قياس المسار (طول الكيبل)';

    protected static ?string $slug = 'route-measure';

    public static function canAccess(): bool
    {
        return auth()->user()->can('routes.measure');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('routes.measure') ?? false;
    }
}
