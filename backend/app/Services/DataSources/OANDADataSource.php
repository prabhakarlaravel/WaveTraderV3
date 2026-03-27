<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class OANDADataSource implements DataSourceInterface
{
    // Rate limit: 100 requests/second (rule #5)
    private const RATE_LIMIT = 100;

    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        // TODO: Implement OANDA REST v20 candles
        // - Map timeframe to OANDA granularity
        // - Handle practice vs live base URLs
        // - Bearer token auth
        // - Account for weekend gaps in forex

        return collect();
    }

    public function supportsRealtime(): bool
    {
        return true; // Pricing stream API
    }

    public function getName(): string
    {
        return 'oanda';
    }
}
