<?php

declare(strict_types=1);

namespace App\Services\GapDetection;

use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NSE-specific gap detection and fill service.
 *
 * KEY DESIGN: Works on a per-trading-day basis in IST timezone.
 * - NSE hours: 09:15–15:30 IST (03:45–10:00 UTC)
 * - Only weekday trading days are checked
 * - Overnight gaps are EXPECTED, not flagged as missing data
 * - Higher TFs (5M+) are ALWAYS aggregated from 1M — never fetched from exchange
 */
class NSEGapDetector implements GapDetectorInterface
{
    private const TIMEZONE = 'Asia/Kolkata';

    // NSE market hours in IST
    private const MARKET_OPEN_HOUR = 9;
    private const MARKET_OPEN_MINUTE = 15;
    private const MARKET_CLOSE_HOUR = 15;
    private const MARKET_CLOSE_MINUTE = 30;

    // Expected candle counts per day by timeframe
    private const EXPECTED_CANDLES_PER_DAY = [
        '1M'  => 375,  // 6h15m = 375 minutes
        '5M'  => 75,   // 375 / 5
        '15M' => 25,   // 375 / 15
        '1H'  => 7,    // ~6.25 rounded up (09:15, 10:15, 11:15, 12:15, 13:15, 14:15, 15:15)
        '4H'  => 2,    // (09:15–13:15, 13:15–15:30)
        '1D'  => 1,    // 1 daily candle
    ];

    // Minimum candle threshold to consider a day "complete" (% of expected)
    private const COMPLETENESS_THRESHOLD = 0.7;

    private const TIMEFRAME_MINUTES = [
        '1M' => 1, '5M' => 5, '15M' => 15,
        '1H' => 60, '4H' => 240, '1D' => 1440,
    ];

    public function getMarketType(): string
    {
        return 'NSE Session';
    }

    /**
     * Scan all timeframes for gaps on a per-trading-day basis.
     */
    public function scan(Symbol $symbol): array
    {
        $timeframes = ['1M', '5M', '15M', '1H', '4H', '1D'];
        $results = [];
        $allGaps = [];

        // Clear stale gap records
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
            'groupedGaps' => $this->groupGaps($allGaps),
            'totalGaps'   => count($allGaps),
        ];
    }

    /**
     * Scan a single timeframe by checking each TRADING DAY for completeness.
     */
    private function scanTimeframe(Symbol $symbol, string $tf): array
    {
        $now = Carbon::now(self::TIMEZONE);
        $rangeStart = $now->copy()->subMonths(3)->startOfDay();

        // Get all candles for this TF, grouped by IST date
        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $tf)
            ->where('timestamp', '>=', $rangeStart->copy()->setTimezone('UTC'))
            ->orderBy('timestamp')
            ->get();

        $totalCandles = $candles->count();

        if ($totalCandles === 0) {
            // No data at all — create one big gap record
            $gapStart = $rangeStart->copy()->setTimezone('UTC');
            $gapEnd = $now->copy()->setTimezone('UTC');

            DataGap::updateOrCreate(
                ['symbol_id' => $symbol->id, 'timeframe' => $tf, 'gap_start' => $gapStart],
                ['gap_end' => $gapEnd, 'filled_at' => null]
            );

            return [
                'timeframe'    => $tf,
                'totalCandles' => 0,
                'gaps'         => [[
                    'gapType'        => 'no_data',
                    'gapStart'       => $gapStart->toIso8601String(),
                    'gapEnd'         => $gapEnd->toIso8601String(),
                    'durationMinutes' => (int) abs($gapStart->diffInMinutes($gapEnd)),
                    'missingCandles' => 0,
                ]],
                'gapCount'     => 1,
                'healthPct'    => 0,
                'timeline'     => [],
            ];
        }

        // Group candles by IST date
        $candlesByDate = [];
        foreach ($candles as $c) {
            $istDate = Carbon::parse($c->timestamp, 'UTC')
                ->setTimezone(self::TIMEZONE)
                ->format('Y-m-d');
            if (!isset($candlesByDate[$istDate])) {
                $candlesByDate[$istDate] = 0;
            }
            $candlesByDate[$istDate]++;
        }

        // Determine the data range
        $firstCandleIST = Carbon::parse($candles->first()->timestamp, 'UTC')->setTimezone(self::TIMEZONE);
        $dataStart = $firstCandleIST->copy()->startOfDay();

        // Iterate over trading days and check completeness
        $gaps = [];
        $timelineSegments = [];
        $expectedPerDay = self::EXPECTED_CANDLES_PER_DAY[$tf] ?? 1;
        $minCandles = max(1, (int) floor($expectedPerDay * self::COMPLETENESS_THRESHOLD));

        $tradingDays = 0;
        $completeDays = 0;
        $gapDays = 0;

        $period = CarbonPeriod::create($dataStart, $now->copy()->startOfDay());

        foreach ($period as $day) {
            $dayIST = $day->copy()->setTimezone(self::TIMEZONE);

            // Skip weekends
            if ($dayIST->isWeekend()) {
                continue;
            }

            // Skip future (today after market close is still valid)
            if ($dayIST->isAfter($now->copy()->startOfDay()) && !$dayIST->isSameDay($now)) {
                continue;
            }

            $tradingDays++;
            $dateKey = $dayIST->format('Y-m-d');
            $candleCount = $candlesByDate[$dateKey] ?? 0;

            if ($candleCount >= $minCandles) {
                $completeDays++;
                $timelineSegments[] = ['type' => 'ok', 'date' => $dateKey];
                continue;
            }

            // This trading day is incomplete — it's a real gap
            $gapDays++;

            // Gap covers market hours of this specific day (in UTC for DB storage)
            $gapStartUTC = $dayIST->copy()
                ->setTime(self::MARKET_OPEN_HOUR, self::MARKET_OPEN_MINUTE)
                ->setTimezone('UTC');
            $gapEndUTC = $dayIST->copy()
                ->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE)
                ->setTimezone('UTC');

            $durationMinutes = (int) abs($gapStartUTC->diffInMinutes($gapEndUTC));
            $missingCandles = max(0, $expectedPerDay - $candleCount);

            $gaps[] = [
                'gapType'         => $candleCount === 0 ? 'full_day' : 'partial_day',
                'gapStart'        => $gapStartUTC->toIso8601String(),
                'gapEnd'          => $gapEndUTC->toIso8601String(),
                'date'            => $dateKey,
                'durationMinutes' => $durationMinutes,
                'missingCandles'  => $missingCandles,
                'existingCandles' => $candleCount,
            ];

            $timelineSegments[] = ['type' => 'gap', 'date' => $dateKey];

            // Persist gap record
            DataGap::create([
                'symbol_id' => $symbol->id,
                'timeframe' => $tf,
                'gap_start' => $gapStartUTC,
                'gap_end'   => $gapEndUTC,
            ]);
        }

        // Health calculation
        $healthPct = $tradingDays > 0
            ? (int) round($completeDays / $tradingDays * 100)
            : 0;

        // Normalize timeline to percentages
        $normalizedTimeline = [];
        $totalDays = max(1, count($timelineSegments));
        foreach ($timelineSegments as $i => $seg) {
            $startPct = round($i / $totalDays * 100, 1);
            $widthPct = round(1 / $totalDays * 100, 1);
            if ($widthPct < 0.3) {
                $widthPct = 0.5;
            }
            $normalizedTimeline[] = [
                'type'     => $seg['type'],
                'startPct' => $startPct,
                'widthPct' => max(0.5, $widthPct),
                'start'    => $seg['date'],
                'end'      => $seg['date'],
            ];
        }

        return [
            'timeframe'    => $tf,
            'totalCandles' => $totalCandles,
            'gaps'         => $gaps,
            'gapCount'     => count($gaps),
            'healthPct'    => $healthPct,
            'timeline'     => $normalizedTimeline,
            'tradingDays'  => $tradingDays,
            'completeDays' => $completeDays,
        ];
    }

    /**
     * Fill gaps for a specific timeframe.
     *
     * Strategy:
     * - 1M: Fetch from Zerodha API (only during market-hours ranges)
     * - 5M/15M/1H/4H/1D: ALWAYS aggregate from existing 1M data.
     *   If 1M is also missing for that day, fetch 1M first then aggregate.
     */
    public function fill(Symbol $symbol, string $timeframe): int
    {
        $gaps = DataGap::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->unfilled()
            ->orderBy('gap_start')
            ->get();

        if ($gaps->isEmpty()) {
            Log::info("NSEGapDetector: No unfilled gaps for {$symbol->ticker} [{$timeframe}]");
            return 0;
        }

        Log::info("NSEGapDetector: Filling {$gaps->count()} gaps for {$symbol->ticker} [{$timeframe}]");

        $totalFilled = 0;

        foreach ($gaps as $gap) {
            $from = Carbon::parse($gap->gap_start);
            $to = Carbon::parse($gap->gap_end);

            Log::info("NSEGapDetector: Gap {$from->toDateString()} [{$timeframe}]");

            if ($timeframe === '1M') {
                // Fetch 1M candles from Zerodha for this specific trading day's market hours
                $filled = $this->fetch1MFromExchange($symbol, $from, $to);
                $totalFilled += $filled;

                if ($filled > 0) {
                    // Also aggregate higher TFs for this day
                    $this->aggregateAllHigherTFs($symbol->id, $from, $to);
                    $gap->update(['filled_at' => Carbon::now()]);
                    Log::info("NSEGapDetector: Filled {$filled} 1M candles for {$from->toDateString()}");
                } else {
                    Log::warning("NSEGapDetector: 0 candles from Zerodha for {$from->toDateString()} — may be a holiday");
                    // Mark as filled if it's likely a holiday (no data available)
                    $gap->update(['filled_at' => Carbon::now()]);
                }
            } else {
                // Higher TFs: aggregate from existing 1M data
                $existing1M = Candle::where('symbol_id', $symbol->id)
                    ->where('timeframe', '1M')
                    ->whereBetween('timestamp', [$from, $to])
                    ->count();

                if ($existing1M > 0) {
                    // 1M data exists — aggregate directly
                    $aggregated = $this->aggregateTimeframe($symbol->id, $timeframe, $from, $to);
                    $totalFilled += $aggregated;

                    if ($aggregated > 0) {
                        $gap->update(['filled_at' => Carbon::now()]);
                        Log::info("NSEGapDetector: Aggregated {$aggregated} {$timeframe} candles from {$existing1M} 1M candles");
                    }
                } else {
                    // No 1M data — fetch from exchange first
                    $fetched = $this->fetch1MFromExchange($symbol, $from, $to);
                    if ($fetched > 0) {
                        $aggregated = $this->aggregateTimeframe($symbol->id, $timeframe, $from, $to);
                        $totalFilled += $fetched + $aggregated;
                        $gap->update(['filled_at' => Carbon::now()]);
                        Log::info("NSEGapDetector: Fetched {$fetched} 1M + aggregated {$aggregated} {$timeframe}");
                    } else {
                        // Likely a holiday — mark as filled
                        $gap->update(['filled_at' => Carbon::now()]);
                        Log::info("NSEGapDetector: No data for {$from->toDateString()} — marking as holiday");
                    }
                }
            }
        }

        return $totalFilled;
    }

    /**
     * Fetch 1M candles from Zerodha for a specific market-hours range.
     */
    private function fetch1MFromExchange(Symbol $symbol, Carbon $from, Carbon $to): int
    {
        try {
            $dataSource = new ZerodhaDataSource();
            $candles = $dataSource->fetchCandles($symbol->ticker, '1M', $from, $to);

            if ($candles->isEmpty()) {
                return 0;
            }

            $mapped = $candles->map(fn(array $c) => [
                ...$c,
                'symbol_id' => $symbol->id,
                'timeframe' => '1M',
            ])->toArray();

            Candle::upsertCandles($mapped);

            return $candles->count();
        } catch (\Throwable $e) {
            Log::error("NSEGapDetector: fetch1M failed for {$symbol->ticker}: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Aggregate a higher timeframe from 1M base data.
     */
    private function aggregateTimeframe(int $symbolId, string $timeframe, Carbon $from, Carbon $to): int
    {
        $intervalMinutes = self::TIMEFRAME_MINUTES[$timeframe] ?? 5;

        // Get 1M candles in range (with buffer for bucket alignment)
        $candles1M = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', '1M')
            ->whereBetween('timestamp', [
                $from->copy()->subMinutes($intervalMinutes),
                $to->copy()->addMinutes($intervalMinutes),
            ])
            ->orderBy('timestamp')
            ->get();

        if ($candles1M->isEmpty()) {
            return 0;
        }

        // Group into time buckets
        $buckets = [];
        foreach ($candles1M as $c) {
            $ts = Carbon::parse($c->timestamp);
            $totalMinutes = $ts->hour * 60 + $ts->minute;
            $bucketMinute = (int) (floor($totalMinutes / $intervalMinutes) * $intervalMinutes);
            $bucketTime = $ts->copy()->startOfDay()->addMinutes($bucketMinute);
            $key = $bucketTime->format('Y-m-d H:i:s');

            if (!isset($buckets[$key])) {
                $buckets[$key] = ['candles' => [], 'timestamp' => $key];
            }
            $buckets[$key]['candles'][] = $c;
        }

        // Aggregate each bucket
        $aggregated = [];
        foreach ($buckets as $key => $bucket) {
            if (empty($bucket['candles'])) {
                continue;
            }

            $first = $bucket['candles'][0];
            $last = end($bucket['candles']);
            $high = max(array_map(fn($c) => (float) $c->high, $bucket['candles']));
            $low = min(array_map(fn($c) => (float) $c->low, $bucket['candles']));
            $volume = array_sum(array_map(fn($c) => (float) $c->volume, $bucket['candles']));

            $aggregated[] = [
                'symbol_id' => $symbolId,
                'timeframe' => $timeframe,
                'timestamp' => $bucket['timestamp'],
                'open'      => (float) $first->open,
                'high'      => $high,
                'low'       => $low,
                'close'     => (float) $last->close,
                'volume'    => $volume,
            ];
        }

        if (!empty($aggregated)) {
            Candle::upsertCandles($aggregated);
        }

        return count($aggregated);
    }

    /**
     * After fetching 1M data, aggregate all higher TFs.
     */
    private function aggregateAllHigherTFs(int $symbolId, Carbon $from, Carbon $to): void
    {
        $higherTFs = ['5M', '15M', '1H', '4H', '1D'];

        foreach ($higherTFs as $tf) {
            $count = $this->aggregateTimeframe($symbolId, $tf, $from, $to);
            if ($count > 0) {
                Log::debug("NSEGapDetector: auto-aggregated {$count} {$tf} candles");
            }
        }
    }

    /**
     * Group overlapping gaps across timeframes.
     */
    private function groupGaps(array $allGaps): array
    {
        if (empty($allGaps)) {
            return [];
        }

        usort($allGaps, fn($a, $b) => strcmp($a['gapStart'], $b['gapStart']));

        $grouped = [];
        $current = null;

        foreach ($allGaps as $gap) {
            if ($current === null) {
                $current = [
                    'gapStart'       => $gap['gapStart'],
                    'gapEnd'         => $gap['gapEnd'],
                    'gapType'        => $gap['gapType'],
                    'date'           => $gap['date'] ?? null,
                    'durationMinutes' => $gap['durationMinutes'],
                    'timeframes'     => [$gap['timeframe']],
                    'missingByTf'    => [$gap['timeframe'] => $gap['missingCandles']],
                ];
                continue;
            }

            // Merge if same date or overlapping
            $sameDate = isset($gap['date']) && isset($current['date']) && $gap['date'] === $current['date'];
            $currentEnd = Carbon::parse($current['gapEnd']);
            $gapStart = Carbon::parse($gap['gapStart']);
            $overlapping = abs($gapStart->diffInMinutes($currentEnd)) < 60 || $gapStart->lte($currentEnd);

            if ($sameDate || $overlapping) {
                $gapEnd = Carbon::parse($gap['gapEnd']);
                if ($gapEnd->gt($currentEnd)) {
                    $current['gapEnd'] = $gap['gapEnd'];
                    $current['durationMinutes'] = max($current['durationMinutes'], $gap['durationMinutes']);
                }
                if (!in_array($gap['timeframe'], $current['timeframes'])) {
                    $current['timeframes'][] = $gap['timeframe'];
                }
                $current['missingByTf'][$gap['timeframe']] = $gap['missingCandles'];
            } else {
                $grouped[] = $current;
                $current = [
                    'gapStart'       => $gap['gapStart'],
                    'gapEnd'         => $gap['gapEnd'],
                    'gapType'        => $gap['gapType'],
                    'date'           => $gap['date'] ?? null,
                    'durationMinutes' => $gap['durationMinutes'],
                    'timeframes'     => [$gap['timeframe']],
                    'missingByTf'    => [$gap['timeframe'] => $gap['missingCandles']],
                ];
            }
        }

        if ($current) {
            $grouped[] = $current;
        }

        return $grouped;
    }
}
