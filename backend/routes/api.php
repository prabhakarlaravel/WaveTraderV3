<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\Api\ChartController;
use App\Http\Controllers\Api\GapController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\UdfController;
use App\Http\Controllers\Api\WaveController;
use Illuminate\Support\Facades\Route;

// Auth routes
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// API v1 routes
Route::prefix('v1')->group(function () {
    // Chart data
    Route::get('chart/candles', [ChartController::class, 'candles']);
    Route::get('chart/candles/latest', [ChartController::class, 'fetchLatest']);
    Route::get('chart/market-status', [ChartController::class, 'marketStatus']);
    Route::get('chart/overlays', [ChartController::class, 'overlays']);
    Route::get('chart/mtf-waves', [ChartController::class, 'mtfWaves']);
    Route::get('chart/symbols', [ChartController::class, 'symbols']);
    Route::post('chart/symbols', [ChartController::class, 'storeSymbol']);
    Route::patch('chart/symbols/{symbol}', [ChartController::class, 'updateSymbol']);
    Route::delete('chart/symbols/{symbol}', [ChartController::class, 'deleteSymbol']);

    // Wave data
    Route::get('waves/{symbol}', [WaveController::class, 'index']);
    Route::get('waves/{symbol}/health', [WaveController::class, 'health']);

    // Signals
    Route::get('signals/{symbol}', [WaveController::class, 'signals']);

    // Trades (auth required)
    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('trades', TradeController::class);
        Route::post('trades/auto', [TradeController::class, 'autoTrade']);
        Route::get('trades/analytics/summary', [TradeController::class, 'analytics']);
    });

    // Backtests
    Route::get('backtests', [BacktestController::class, 'index']);
    Route::post('backtests', [BacktestController::class, 'store']);
    Route::get('backtests/{backtest}', [BacktestController::class, 'show']);

    // Settings
    Route::get('settings', [SettingsController::class, 'index']);
    Route::put('settings', [SettingsController::class, 'update']);
    Route::post('settings/test', [SettingsController::class, 'testConnection']);
    // Zerodha token flow (must be before settings/{group} wildcard)
    Route::get('settings/zerodha/login-url', [SettingsController::class, 'zerodhaLoginUrl']);
    Route::post('settings/zerodha/exchange-token', [SettingsController::class, 'zerodhaExchangeToken']);
    Route::get('settings/zerodha/balance', [SettingsController::class, 'zerodhaBalance']);
    Route::get('settings/{group}', [SettingsController::class, 'group']);

    // Data gaps
    Route::get('gaps', [GapController::class, 'index']);
    Route::post('gaps/scan', [GapController::class, 'scan']);
    Route::post('gaps/fill', [GapController::class, 'fill']);
    Route::get('gaps/health', [GapController::class, 'health']);

    // Wave health
    Route::get('wave-health', [WaveController::class, 'healthDashboard']);
    Route::post('wave-health/validate', [WaveController::class, 'validateAll']);
    Route::post('wave-health/fix', [WaveController::class, 'autoFix']);
    Route::post('wave-health/regenerate', [WaveController::class, 'regenerateWaves']);
});

// UDF endpoints (TradingView Charting Library) — no Sanctum middleware
Route::prefix('udf')->group(function () {
    Route::get('config', [UdfController::class, 'config']);
    Route::get('symbols', [UdfController::class, 'symbols']);
    Route::get('search', [UdfController::class, 'search']);
    Route::get('history', [UdfController::class, 'history']);
    Route::get('marks', [UdfController::class, 'marks']);
    Route::get('timescale_marks', [UdfController::class, 'timescaleMarks']);
    Route::get('streaming', [UdfController::class, 'streaming']);
});
