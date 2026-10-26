<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/lookup', [PosController::class, 'lookup'])->name('pos.lookup');
    Route::get('/pos/carts/{cart}', [CartController::class, 'show'])->name('pos.cart');
    Route::post('/pos/carts/{cart}', [CartController::class, 'mutate'])->name('pos.mutate');
    Route::post('/pos/carts/{cart}/hold', [CartController::class, 'hold'])->name('pos.hold');
    Route::post('/pos/carts/{cart}/resume', [CartController::class, 'resume'])->name('pos.resume');
    Route::post('/pos/carts/{cart}/cancel', [CartController::class, 'cancel'])->name('pos.cancel');
    Route::post('/pos/carts/{cart}/approval', [ApprovalController::class, 'store'])->middleware('throttle:10,15')->name('pos.approval');
});
