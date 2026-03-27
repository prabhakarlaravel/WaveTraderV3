<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GapDetectionService
{
    private const TIMEFRAME_MINUTES = [
        '1M' => 1, '5M' => 5, '15M' => 15,
        '1H' => 60, '4H' => 240, '1D' => 1440,
    ];

    public function detect(Symbol $symbol, string $timeframe): Collection
    {
        $intervalMinutes = self::TIMEFRAME_MINUTES[$timeframe] ?? 1;

        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->pluck('timestamp');

        if ($candles->count() < 2) {
            return collect();
        }

        $gaps = collect();

        for ($i = 1; $i < $candles->count(); $i++) {
            $expected = $candles[$i - 1]->copy()->addMinutes($intervalMinutes);
            $actual = $candles[$i];

            // Allow tolerance for market closures (weekends, holidays)
            $diffMinutes = $expected->diffInMinutes($actual);

            if ($diffMinutes > $intervalMinutes * 2) {
                $gap = DataGap::firstOrCreate([
                    'symbol_id' => $symbol->id,
                    'timeframe' => $timeframe,
                    'gap_start' => $expected,
                    'gap_end' => $actual,
                ]);

                $gaps->push($gap);
            }
        }

        return $gaps;
    }

    public function fill(Symbol $symbol, string $timeframe, Collection $gaps): void
    {
        // TODO: For each gap, fetch missing candles from the exchange data source
        // and mark the gap as filled

        foreach ($gaps as $gap) {
            // $dataSource->fetchCandles($symbol->ticker, $timeframe, $gap->gap_start, $gap->gap_end);
            $gap->update(['filled_at' => Carbon::now()]);
        }
    }
}
