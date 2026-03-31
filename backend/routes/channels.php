<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Public channels — no auth required
Broadcast::channel('candles.{symbol}.{timeframe}', fn () => true);
Broadcast::channel('waves.{symbol}', fn () => true);
Broadcast::channel('signals.{symbol}', fn () => true);
Broadcast::channel('health.{symbol}', fn () => true);
Broadcast::channel('overlays.{symbol}.{timeframe}', fn () => true);

// Private channel — user-specific trades
Broadcast::channel('trades.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
