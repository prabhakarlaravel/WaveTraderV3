<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ZerodhaDataSource implements DataSourceInterface
{
    // Rate limit: 3 requests/second (rule #5)
    private const RATE_LIMIT = 3;

    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        // TODO: Implement KiteConnect v3 REST historical data
        // - Max 60 days per request
        // - Map timeframe to KiteConnect interval
        // - Handle access token (auto-renewed daily, rule #6)
        // - Implement rate limiting (3 req/sec)
        // - Exponential backoff on 429

        return collect();
    }

    public function supportsRealtime(): bool
    {
        return true; // KTicker WebSocket
    }

    public function getName(): string
    {
        return 'zerodha';
    }
}
