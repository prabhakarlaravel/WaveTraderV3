<?php

declare(strict_types=1);

namespace App\Services\LiveFeed;

use App\Models\Symbol;

/**
 * Resolves the appropriate LiveFeed service based on symbol's exchange/type.
 *
 * Each market type has its own service with market-specific:
 * - Market hours awareness (NSE: 9:15-15:30 IST, Crypto: 24/7, Forex: Sun-Fri)
 * - Timezone handling (Zerodha expects IST, Binance uses UTC)
 * - Rate limiting and session management
 */
class LiveFeedResolver
{
    private static array $instances = [];

    /**
     * Resolve the live feed service for a given symbol.
     */
    public static function resolve(Symbol $symbol): LiveFeedInterface
    {
        $marketType = self::detectMarketType($symbol);

        // Cache instances per market type to avoid re-instantiation
        if (! isset(self::$instances[$marketType])) {
            self::$instances[$marketType] = match ($marketType) {
                'crypto' => new CryptoLiveFeed(),
                'nse' => new NSELiveFeed(),
                'forex' => new ForexLiveFeed(),
                default => throw new \RuntimeException("No LiveFeed service for market type: {$marketType}"),
            };
        }

        return self::$instances[$marketType];
    }

    /**
     * Detect market type from symbol properties.
     */
    public static function detectMarketType(Symbol $symbol): string
    {
        $exchange = strtoupper($symbol->exchange ?? '');
        $type = strtolower($symbol->type ?? '');

        // Indian markets → NSE service (handles NSE, BSE, NFO, MCX via Zerodha)
        if (in_array($exchange, ['NSE', 'BSE', 'NFO', 'MCX', 'ZERODHA'])) {
            return 'nse';
        }

        // Crypto exchanges → Crypto service
        if (in_array($exchange, ['BINANCE', 'BINANCE_FUTURES']) || $type === 'crypto') {
            return 'crypto';
        }

        // Forex exchanges → Forex service
        if (in_array($exchange, ['OANDA', 'FOREX']) || $type === 'forex') {
            return 'forex';
        }

        // Yahoo with forex symbols → Forex service
        if ($exchange === 'YAHOO' && str_contains($symbol->ticker, '=X')) {
            return 'forex';
        }

        // Yahoo with Indian index symbols (^NSEI, ^BSESN) → NSE service
        if ($exchange === 'YAHOO' && str_starts_with($symbol->ticker, '^')) {
            return 'nse';
        }

        // Default to crypto (most permissive — 24/7)
        return 'crypto';
    }

    /**
     * Get market status for all registered market types.
     */
    public static function getAllMarketStatus(): array
    {
        $statuses = [];
        $types = ['crypto' => CryptoLiveFeed::class, 'nse' => NSELiveFeed::class, 'forex' => ForexLiveFeed::class];

        foreach ($types as $type => $class) {
            $feed = self::$instances[$type] ?? new $class();
            $statuses[$type] = $feed->getMarketStatus();
        }

        return $statuses;
    }
}
