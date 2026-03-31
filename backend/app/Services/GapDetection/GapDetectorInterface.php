<?php

declare(strict_types=1);

namespace App\Services\GapDetection;

use App\Models\Symbol;

interface GapDetectorInterface
{
    /**
     * Scan all timeframes for gaps — returns structured gap data.
     */
    public function scan(Symbol $symbol): array;

    /**
     * Fill gaps for a specific timeframe — returns number of candles filled.
     */
    public function fill(Symbol $symbol, string $timeframe): int;

    /**
     * Get the market type identifier.
     */
    public function getMarketType(): string;
}
