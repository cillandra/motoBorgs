<?php

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

Route::get('/', function () {
    return view('welcome');
});
