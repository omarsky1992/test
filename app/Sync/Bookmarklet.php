<?php

namespace App\Sync;

/**
 * @see BrowserScripts
 */
class Bookmarklet
{
    public static function href(string $appUrl, string $clientApp): string
    {
        return BrowserScripts::bookmarkletHref($appUrl, $clientApp);
    }
}
