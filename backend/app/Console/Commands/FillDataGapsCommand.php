<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\GapDetection\GapDetectorResolver;
use Illuminate\Console\Command;

class FillDataGapsCommand extends Command
{
    protected $signature = 'gaps:fill
        {--symbol= : Specific symbol ticker}
        {--timeframe=1M : Timeframe to check}
        {--detect-only : Only detect gaps, do not fill}
        {--all-tf : Fill all timeframes (1M through 1D)}';

    protected $description = 'Detect and fill missing candle data gaps using market-specific detectors';

    public function handle(): int
    {
        $timeframe = $this->option('timeframe');
        $detectOnly = $this->option('detect-only');
        $allTf = $this->option('all-tf');

        $symbols = $this->option('symbol')
            ? Symbol::where('ticker', $this->option('symbol'))->get()
            : Symbol::active()->get();

        foreach ($symbols as $symbol) {
            $detector = GapDetectorResolver::resolve($symbol);
            $this->info("{$symbol->ticker}: Using {$detector->getMarketType()} detector");

            $result = $detector->scan($symbol);
            $totalGaps = $result['totalGaps'] ?? 0;

            if ($totalGaps === 0) {
                $this->info("{$symbol->ticker}: No gaps found");
                continue;
            }

            $this->info("{$symbol->ticker}: Found {$totalGaps} gaps across all timeframes");

            foreach ($result['timeframes'] as $tf => $tfData) {
                $gapCount = $tfData['gapCount'] ?? 0;
                if ($gapCount > 0) {
                    $this->line("  {$tf}: {$gapCount} gaps, {$tfData['healthPct']}% health");
                }
            }

            if (!$detectOnly) {
                $tfsToFill = $allTf
                    ? ['1M', '5M', '15M', '1H', '4H', '1D']
                    : [$timeframe];

                foreach ($tfsToFill as $tf) {
                    $gapCount = $result['timeframes'][$tf]['gapCount'] ?? 0;
                    if ($gapCount > 0) {
                        $filled = $detector->fill($symbol, $tf);
                        $this->info("{$symbol->ticker} [{$tf}]: Filled {$filled} candles");
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
