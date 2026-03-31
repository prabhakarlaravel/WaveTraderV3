<?php

declare(strict_types=1);

namespace App\Services\GapDetection;

use App\Models\Symbol;
use App\Services\LiveFeed\LiveFeedResolver;

/**
 * Resolves the appropriate GapDetector based on the symbol's market type.
 *
 * Uses the same market detection logic as LiveFeedResolver for consistency.
 */
class GapDetectorResolver
{
    private static array $instances = [];

    public static function resolve(Symbol $symbol): GapDetectorInterface
    {
        $marketType = LiveFeedResolver::detectMarketType($symbol);

        if (!isset(self::$instances[$marketType])) {
            self::$instances[$marketType] = match ($marketType) {
                'nse'    => new NSEGapDetector(),
                'crypto' => new CryptoGapDetector(),
                'forex'  => new ForexGapDetector(),
                default  => new CryptoGapDetector(), // fallback to 24/7 logic
            };
        }

        return self::$instances[$marketType];
    }
}
