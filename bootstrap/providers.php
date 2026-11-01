<?php

return [
    App\Providers\AppServiceProvider::class,
    // Before the clients' panel: its routes, bound to the platform address, must be matched first.
    App\Providers\Filament\PlatformPanelProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
