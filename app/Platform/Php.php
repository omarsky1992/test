<?php

namespace App\Platform;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The command-line PHP for child processes. Not PHP_BINARY: inside the web server that is php-fpm,
 * which cannot run `artisan` (adding a client from the panel failed that way).
 */
class Php
{
    public static function binary(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: 'php';
    }
}
