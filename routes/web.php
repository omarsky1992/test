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
    Route::get('/sync/extension.zip', [BrowserSyncController::class, 'extension'])->name('sync.extension');
    Route::post('/sync/browser/plan', [BrowserSyncController::class, 'plan'])->name('sync.browser.plan');
    Route::post('/sync/browser/run', [BrowserSyncController::class, 'run'])->name('sync.browser.run');
    Route::get('/backup/download', function (App\Services\BackupService $backups) {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        @set_time_limit(900);
        $file = $backups->dumpForDownload();

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
    })->name('backup.download');
    Route::get('/backup/server/{kind}/{file}', function (App\Services\BackupService $backups, string $kind, string $file) {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $path = $backups->serverBackupPath("{$kind}/{$file}") ?? abort(404);
        app(App\Services\Audit::class)->log('backup.downloaded', 'backup', null, ['file' => "{$kind}/{$file}", 'source' => 'server']);

        return response()->download($path, $file);
    })->where(['kind' => 'daily|weekly|monthly', 'file' => '[A-Za-z0-9._-]+'])->name('backup.server');
    Route::get('/backup/google/connect', [BackupController::class, 'connect'])->name('backup.google.connect');
    Route::get('/backup/google/callback', [BackupController::class, 'callback'])->name('backup.google.callback');
});

Route::post('/internal/backup', [BackupController::class, 'trigger'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:5,60')
    ->name('backup.trigger');

// WhatsApp Cloud API webhook: Meta's verification (GET) and signed message deliveries (POST).
Route::get('/whatsapp/webhook', [App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
Route::post('/whatsapp/webhook', [App\Http\Controllers\WhatsAppWebhookController::class, 'receive'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:300,1')
    ->name('whatsapp.webhook');
