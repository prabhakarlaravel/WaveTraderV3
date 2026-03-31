<?php

declare(strict_types=1);

namespace App\Services\LiveFeed;

use App\Models\Symbol;
use Illuminate\Support\Collection;

interface LiveFeedInterface
{
    /**
     * Check if this market is currently open for trading.
     */
    public function isMarketOpen(): bool;

    /**
     * Fetch latest candles from exchange, upsert to DB, return fresh candles.
     * Returns empty collection if market is closed.
     */
    public function fetchLatest(Symbol $symbol, string $timeframe, int $limit = 10): Collection;

    /**
     * Get market status info for frontend display.
     */
    public function getMarketStatus(): array;

    /**
     * Get the market type identifier.
     */
    public function getMarketType(): string;
}
