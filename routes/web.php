<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/receipts/{payment}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/backup/google/connect', [BackupController::class, 'connect'])->name('backup.google.connect');
    Route::get('/backup/google/callback', [BackupController::class, 'callback'])->name('backup.google.callback');
});

Route::post('/internal/backup', [BackupController::class, 'trigger'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:5,60')
    ->name('backup.trigger');
