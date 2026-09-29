<?php

use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/receipts/{payment}', [ReceiptController::class, 'show'])->name('receipts.show');
});
