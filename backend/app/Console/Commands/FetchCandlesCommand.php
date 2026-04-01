<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchCandlesJob;
use App\Models\Symbol;
use App\Services\LiveFeed\LiveFeedResolver;
use Illuminate\Console\Command;

class FetchCandlesCommand extends Command
{
    protected $signature = 'candles:fetch
        {--symbol= : Specific symbol ticker to fetch}
        {--timeframe=1M : Timeframe to fetch (1M, 5M, 15M, 1H, 4H, 1D)}
        {--all : Fetch all active symbols}
        {--force : Ignore market hours check}';

    protected $description = 'Dispatch candle fetch jobs for active symbols (market-hours aware)';

    public function handle(): int
    {
        $timeframe = $this->option('timeframe');
        $force = $this->option('force');

        if ($ticker = $this->option('symbol')) {
            $symbol = Symbol::where('ticker', $ticker)->firstOrFail();

            if (! $force && ! $this->isMarketOpen($symbol)) {
                $this->line("Skipped {$ticker} — market closed");

                return self::SUCCESS;
            }

            FetchCandlesJob::dispatch($symbol->id, $timeframe);
            $this->info("Dispatched fetch for {$ticker} [{$timeframe}]");

            return self::SUCCESS;
        }

        $symbols = Symbol::active()->get();

        if ($symbols->isEmpty()) {
            $this->warn('No active symbols found.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($symbols as $symbol) {
            if (! $force && ! $this->isMarketOpen($symbol)) {
                $skipped++;

                continue;
            }

            FetchCandlesJob::dispatch($symbol->id, $timeframe);
            $dispatched++;
        }

        $this->info("Dispatched fetch for {$dispatched} symbols [{$timeframe}]"
            . ($skipped > 0 ? " (skipped {$skipped} closed markets)" : ''));

        return self::SUCCESS;
    }

    /**
     * Check if the market is currently open for this symbol.
     * Crypto markets are always open (24/7).
     * NSE/BSE/NFO/MCX: weekdays 09:00–15:35 IST.
     * Forex: Sun 22:00 UTC – Fri 22:00 UTC.
     */
    private function isMarketOpen(Symbol $symbol): bool
    {
        try {
            $resolver = app(LiveFeedResolver::class);
            $feed = $resolver->resolve($symbol);
            $status = $feed->getMarketStatus($symbol);

            return $status['open'] ?? true;
        } catch (\Throwable) {
            // If we can't determine market status, assume open (safer to over-fetch)
            return true;
        }
    }
}
