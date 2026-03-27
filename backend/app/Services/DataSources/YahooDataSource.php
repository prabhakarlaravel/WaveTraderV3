<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class YahooDataSource implements DataSourceInterface
{
    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        // TODO: Implement Yahoo Finance REST (unofficial)
        // - Max 60 days historical for intraday
        // - Unreliable — fallback only (see CLAUDE.md note)
        // - Prefer Zerodha MCX segment for Indian commodity data

        return collect();
    }

    public function supportsRealtime(): bool
    {
        return false;
    }

    public function getName(): string
    {
        return 'yahoo';
    }
}
