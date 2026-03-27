<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunEnginesJob;
use App\Models\Symbol;
use Illuminate\Console\Command;

class RunEnginesCommand extends Command
{
    protected $signature = 'engines:run
        {--symbol= : Specific symbol ticker}
        {--timeframe=1M : Timeframe to run engines on}';

    protected $description = 'Dispatch engine run jobs for active symbols';

    public function handle(): int
    {
        $timeframe = $this->option('timeframe');

        if ($ticker = $this->option('symbol')) {
            $symbol = Symbol::where('ticker', $ticker)->firstOrFail();
            RunEnginesJob::dispatch($symbol->id, $timeframe);
            $this->info("Dispatched engines for {$ticker} [{$timeframe}]");

            return self::SUCCESS;
        }

        $symbols = Symbol::active()->get();

        foreach ($symbols as $symbol) {
            RunEnginesJob::dispatch($symbol->id, $timeframe);
        }

        $this->info("Dispatched engines for {$symbols->count()} symbols [{$timeframe}]");

        return self::SUCCESS;
    }
}
