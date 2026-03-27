<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchCandlesJob;
use App\Models\Symbol;
use Illuminate\Console\Command;

class FetchCandlesCommand extends Command
{
    protected $signature = 'candles:fetch
        {--symbol= : Specific symbol ticker to fetch}
        {--timeframe=1M : Timeframe to fetch (1M, 5M, 15M, 1H, 4H, 1D)}
        {--all : Fetch all active symbols}';

    protected $description = 'Dispatch candle fetch jobs for active symbols';

    public function handle(): int
    {
        $timeframe = $this->option('timeframe');

        if ($ticker = $this->option('symbol')) {
            $symbol = Symbol::where('ticker', $ticker)->firstOrFail();
            FetchCandlesJob::dispatch($symbol->id, $timeframe);
            $this->info("Dispatched fetch for {$ticker} [{$timeframe}]");

            return self::SUCCESS;
        }

        $symbols = Symbol::active()->get();

        if ($symbols->isEmpty()) {
            $this->warn('No active symbols found.');

            return self::SUCCESS;
        }

        foreach ($symbols as $symbol) {
            FetchCandlesJob::dispatch($symbol->id, $timeframe);
        }

        $this->info("Dispatched fetch for {$symbols->count()} symbols [{$timeframe}]");

        return self::SUCCESS;
    }
}
