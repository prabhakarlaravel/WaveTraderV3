<?php

declare(strict_types=1);

namespace App\Services\GapDetection;

use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use App\Services\DataSources\OANDADataSource;
use App\Services\DataSources\YahooDataSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Forex-specific gap detection.
 *
 * Forex hours: Sunday 22:00 UTC – Friday 22:00 UTC (continuous).
 * Weekend gaps (Sat + Sun before 22:00) are expected, not flagged.
 */
class ForexGapDetector implements GapDetectorInterface
{
    private const TIMEFRAME_MINUTES = [
        '1M' => 1, '5M' => 5, '15M' => 15,
        '1H' => 60, '4H' => 240, '1D' => 1440,
    ];

    public function getMarketType(): string
    {
        return 'Forex';
    }

    public function scan(Symbol $symbol): array
    {
        $timeframes = ['1M', '5M', '15M', '1H', '4H', '1D'];
        $results = [];
        $allGaps = [];

        DataGap::where('symbol_id', $symbol->id)->delete();

        foreach ($timeframes as $tf) {
            $tfResult = $this->scanTimeframe($symbol, $tf);
            $results[$tf] = $tfResult;

            foreach ($tfResult['gaps'] as $gap) {
                $allGaps[] = [...$gap, 'timeframe' => $tf];
            }
        }

        return [
            'symbol'      => $symbol->ticker,
            'exchange'    => $symbol->exchange,
            'marketType'  => $this->getMarketType(),
            'timeframes'  => $results,
            'groupedGaps' => [],
            'totalGaps'   => count($allGaps),
        ];
    }

    private function scanTimeframe(Symbol $symbol, string $tf): array
    {
        $intervalMinutes = self::TIMEFRAME_MINUTES[$tf] ?? 1;

        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $tf)
            ->orderBy('timestamp')
            ->pluck('timestamp');

        $totalCandles = $candles->count();

        if ($totalCandles < 2) {
            $noDataGaps = [];
            if ($totalCandles === 0) {
                $rangeStart = Carbon::now()->subMonths(3)->startOfDay();
                $rangeEnd = Carbon::now();
                $noDataGaps[] = [
                    'gapType'         => 'no_data',
                    'gapStart'        => $rangeStart->toIso8601String(),
                    'gapEnd'          => $rangeEnd->toIso8601String(),
                    'durationMinutes' => (int) abs($rangeStart->diffInMinutes($rangeEnd)),
                    'missingCandles'  => 0,
                ];

                DataGap::updateOrCreate(
                    ['symbol_id' => $symbol->id, 'timeframe' => $tf, 'gap_start' => $rangeStart],
                    ['gap_end' => $rangeEnd, 'filled_at' => null]
                );
            }
            return [
                'timeframe'    => $tf,
                'totalCandles' => $totalCandles,
                'gaps'         => $noDataGaps,
                'gapCount'     => count($noDataGaps),
                'healthPct'    => 0,
                'timeline'     => [],
            ];
        }

        $gaps = [];
        $thresholdMinutes = (int) ceil($intervalMinutes * 1.5);

        for ($i = 1; $i < $candles->count(); $i++) {
            $prev = $candles[$i - 1];
            $curr = $candles[$i];
            $diffMinutes = (int) abs($prev->diffInMinutes($curr));

            if ($diffMinutes > $thresholdMinutes) {
                // Skip weekend gaps (Fri 22:00 – Sun 22:00 UTC)
                if ($this->isWeekendGap($prev, $curr)) {
                    continue;
                }

                $gapStart = $prev->copy()->addMinutes($intervalMinutes);
                $missingCandles = max(0, (int) floor($diffMinutes / $intervalMinutes) - 1);

                $gaps[] = [
                    'gapType'         => 'internal',
                    'gapStart'        => $gapStart->toIso8601String(),
                    'gapEnd'          => $curr->toIso8601String(),
                    'durationMinutes' => $diffMinutes,
                    'missingCandles'  => $missingCandles,
                ];

                DataGap::create([
                    'symbol_id' => $symbol->id,
                    'timeframe' => $tf,
                    'gap_start' => $gapStart,
                    'gap_end'   => $curr,
                ]);
            }
        }

        $firstCandle = $candles->first();
        $now = Carbon::now()->utc();
        $totalSpanMinutes = max(1, (int) abs($firstCandle->diffInMinutes($now)));
        $gapMinutes = array_sum(array_column($gaps, 'durationMinutes'));
        $healthPct = max(0, min(100, (int) round(100 - ($gapMinutes / $totalSpanMinutes * 100))));

        return [
            'timeframe'    => $tf,
            'totalCandles' => $totalCandles,
            'gaps'         => $gaps,
            'gapCount'     => count($gaps),
            'healthPct'    => $healthPct,
            'timeline'     => [],
        ];
    }

    private function isWeekendGap(Carbon $from, Carbon $to): bool
    {
        // Friday after 22:00 UTC → Sunday/Monday
        $fromDay = $from->dayOfWeek;
        $fromHour = $from->hour;

        // Gap starts on Friday after 22:00 (market close)
        if ($fromDay === Carbon::FRIDAY && $fromHour >= 22) {
            return true;
        }

        // Gap starts on Saturday
        if ($fromDay === Carbon::SATURDAY) {
            return true;
        }

        // Gap starts on Sunday before 22:00 (market still closed)
        if ($fromDay === Carbon::SUNDAY && $fromHour < 22) {
            return true;
        }

        return false;
    }

    public function fill(Symbol $symbol, string $timeframe): int
    {
        $gaps = DataGap::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->unfilled()
            ->get();

        if ($gaps->isEmpty()) {
            return 0;
        }

        $totalFetched = 0;

        foreach ($gaps as $gap) {
            $from = Carbon::parse($gap->gap_start);
            $to = Carbon::parse($gap->gap_end);

            try {
                $dataSource = strtoupper($symbol->exchange) === 'OANDA'
                    ? new OANDADataSource()
                    : new YahooDataSource();

                $candles = $dataSource->fetchCandles($symbol->ticker, $timeframe, $from, $to);

                if ($candles->isNotEmpty()) {
                    $mapped = $candles->map(fn(array $c) => [
                        ...$c,
                        'symbol_id' => $symbol->id,
                        'timeframe' => $timeframe,
                    ])->toArray();
                    Candle::upsertCandles($mapped);
                    $totalFetched += $candles->count();
                }

                $gap->update(['filled_at' => Carbon::now()]);
            } catch (\Throwable $e) {
                Log::error("ForexGapDetector: fill failed — {$e->getMessage()}");
            }
        }

        return $totalFetched;
    }
}
