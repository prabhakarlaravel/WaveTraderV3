<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\GapDetectionService;
use Illuminate\Console\Command;

class FillDataGapsCommand extends Command
{
    protected $signature = 'gaps:fill
        {--symbol= : Specific symbol ticker}
        {--timeframe=1M : Timeframe to check}
        {--detect-only : Only detect gaps, do not fill}';

    protected $description = 'Detect and fill missing candle data gaps';

    public function handle(GapDetectionService $gapService): int
    {
        $timeframe = $this->option('timeframe');
        $detectOnly = $this->option('detect-only');

        $symbols = $this->option('symbol')
            ? Symbol::where('ticker', $this->option('symbol'))->get()
            : Symbol::active()->get();

        foreach ($symbols as $symbol) {
            $gaps = $gapService->detect($symbol, $timeframe);

            if ($gaps->isEmpty()) {
                $this->info("{$symbol->ticker}: No gaps found");
                continue;
            }

            $this->info("{$symbol->ticker}: Found {$gaps->count()} gaps");

            if (! $detectOnly) {
                $gapService->fill($symbol, $timeframe, $gaps);
                $this->info("{$symbol->ticker}: Gaps filled");
            }
        }

        return self::SUCCESS;
    }
}
