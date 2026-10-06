<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\BrowserSyncController;
use App\Http\Controllers\InterfaceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Imports\SubscriberImporter;
use App\Services\Audit;
use App\Services\BackupService;
use App\WhatsApp\WahaGateway;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/receipts/{payment}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/import/subscribers/template', function (SubscriberImporter $importer) {
        abort_unless(auth()->user()->can('subscribers.create'), 403);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $importer->writeTemplate($path);

        return response()->download($path, 'قالب-استيراد-المشتركين.xlsx')->deleteFileAfterSend();
    })->name('import.subscribers.template');
    Route::get('/sync/browser', [BrowserSyncController::class, 'page'])->name('sync.browser');
    Route::get('/sync/extension.zip', [BrowserSyncController::class, 'extension'])->name('sync.extension');
    Route::post('/sync/browser/plan', [BrowserSyncController::class, 'plan'])->name('sync.browser.plan');
    Route::post('/sync/browser/run', [BrowserSyncController::class, 'run'])->name('sync.browser.run');
    Route::get('/backup/download', function (BackupService $backups) {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        @set_time_limit(900);
        $file = $backups->dumpForDownload();

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
    })->name('backup.download');
    Route::get('/backup/server/{kind}/{file}', function (BackupService $backups, string $kind, string $file) {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $path = $backups->serverBackupPath("{$kind}/{$file}") ?? abort(404);
        app(Audit::class)->log('backup.downloaded', 'backup', null, ['file' => "{$kind}/{$file}", 'source' => 'server']);

        return response()->download($path, $file);
    })->where(['kind' => 'daily|weekly|monthly', 'file' => '[A-Za-z0-9._-]+'])->name('backup.server');
    Route::get('/whatsapp/qr.png', function (Request $request) {
        abort_unless(auth()->user()->can('whatsapp.manage'), 403);
        try {
            return response(WahaGateway::line($request->query('line') === 'notify' ? 'notify' : 'main')->qrPng(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
        } catch (Throwable) {
            abort(404);
        }
    })->name('whatsapp.qr');
    Route::post('/ui/theme', [InterfaceController::class, 'theme'])->name('ui.theme');
    Route::post('/ui/mode', [InterfaceController::class, 'mode'])->name('ui.mode');
    Route::get('/backup/google/connect', [BackupController::class, 'connect'])->name('backup.google.connect');
    Route::get('/backup/google/callback', [BackupController::class, 'callback'])->name('backup.google.callback');
});

Route::post('/internal/backup', [BackupController::class, 'trigger'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:5,60')
    ->name('backup.trigger');

// WhatsApp Cloud API webhook: Meta's verification (GET) and signed message deliveries (POST).
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:300,1')
    ->name('whatsapp.webhook');
// The phone linked by QR code (WAHA service on the same server), signed with WAHA_WEBHOOK_SECRET.
Route::post('/whatsapp/qr-webhook', [WhatsAppWebhookController::class, 'receiveQr'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:300,1')
    ->name('whatsapp.qr-webhook');
