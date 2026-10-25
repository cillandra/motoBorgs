<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::select('SELECT 1');
    } catch (Throwable) {
        return response()->json(['app' => 'MOTO', 'database' => 'unavailable'], 503);
    }

    return response()->json(['app' => 'MOTO', 'database' => 'ok']);
});

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active', 'session.lifetime'])->group(function (): void {
    Route::get('/dashboard', fn () => view('dashboard.index'))->name('dashboard');
});
