<?php

use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Zerodha KiteConnect OAuth callback
// Configured redirect URL in Kite app: http://localhost:8000/zerodha/callback
Route::get('/zerodha/callback', [SettingsController::class, 'zerodhaCallback']);
