<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\BrowserSyncController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/receipts/{payment}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/import/subscribers/template', function (App\Imports\SubscriberImporter $importer) {
        abort_unless(auth()->user()->can('subscribers.create'), 403);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $importer->writeTemplate($path);

        return response()->download($path, 'قالب-استيراد-المشتركين.xlsx')->deleteFileAfterSend();
    })->name('import.subscribers.template');
    Route::get('/sync/browser', [BrowserSyncController::class, 'page'])->name('sync.browser');
    Route::post('/sync/browser/plan', [BrowserSyncController::class, 'plan'])->name('sync.browser.plan');
    Route::post('/sync/browser/run', [BrowserSyncController::class, 'run'])->name('sync.browser.run');
    Route::get('/backup/google/connect', [BackupController::class, 'connect'])->name('backup.google.connect');
    Route::get('/backup/google/callback', [BackupController::class, 'callback'])->name('backup.google.callback');
});

Route::post('/internal/backup', [BackupController::class, 'trigger'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:5,60')
    ->name('backup.trigger');
