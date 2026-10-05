<?php

namespace App\Http\Middleware;

use App\Support\Themes;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paints the panel in the signed-in user's own colour.
 */
class ApplyUserTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $color = $request->user()?->theme_color;
        if ($color !== null && isset(Themes::ALL[$color])) {
            FilamentColor::register(['primary' => Themes::palette($color)]);
        }

        return $next($request);
    }
}
