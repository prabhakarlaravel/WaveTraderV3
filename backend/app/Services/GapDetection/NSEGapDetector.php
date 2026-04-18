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

    /**
     * NSE trading holidays (IST dates). Market is closed — these days must not
     * be flagged as gaps. Keep this list in sync each calendar year from
     * https://www.nseindia.com/resources/exchange-communication-holidays
     */
    private const NSE_HOLIDAYS = [
        // 2025
        '2025-01-26', '2025-02-26', '2025-03-14', '2025-03-31', '2025-04-10',
        '2025-04-14', '2025-04-18', '2025-05-01', '2025-08-15', '2025-08-27',
        '2025-10-02', '2025-10-21', '2025-10-22', '2025-11-05', '2025-12-25',
        // 2026
        '2026-01-15', '2026-01-26', '2026-02-19', '2026-03-03', '2026-03-26',
        '2026-03-31', '2026-04-03', '2026-04-14', '2026-05-01', '2026-08-15',
        '2026-08-26', '2026-10-02', '2026-10-21', '2026-11-09', '2026-12-25',
    ];

    private function isNseHoliday(Carbon $dayIST): bool
    {
        return in_array($dayIST->format('Y-m-d'), self::NSE_HOLIDAYS, true);
    }

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

            // Skip NSE trading holidays — market closed, no data expected
            if ($this->isNseHoliday($dayIST)) {
                continue;
            }

            // Skip the current trading day if the session is still in progress.
            // Expected-candle counts assume the full 09:15–15:30 IST window, so
            // mid-session days would always look "incomplete" and get flagged
            // as gaps even though data is streaming in normally.
            if ($dayIST->isSameDay($now)) {
                $marketClose = $now->copy()->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE);
                if ($now->lt($marketClose)) {
                    continue;
                }
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
            // Gap timestamps are stored in UTC. Convert to IST to anchor the full
            // NSE trading-day window (09:15–15:30 IST) regardless of how the gap
            // was originally clipped. This — combined with ZerodhaDataSource's
            // IST-forced formatting — ensures we always ask Zerodha for the real
            // market hours of the correct day.
            $gapStartIST = Carbon::parse($gap->gap_start)->setTimezone(self::TIMEZONE);
            $dayIST = $gapStartIST->copy()->startOfDay();
            $from = $dayIST->copy()->setTime(self::MARKET_OPEN_HOUR, self::MARKET_OPEN_MINUTE);
            $to   = $dayIST->copy()->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE);

            $dateKey = $dayIST->format('Y-m-d');
            $isKnownHoliday = $this->isNseHoliday($dayIST);

            Log::info("NSEGapDetector: Gap {$dateKey} [{$timeframe}]"
                . ($isKnownHoliday ? ' (NSE holiday — skipping fetch)' : ''));

            // Known NSE holiday — mark filled immediately, no fetch needed
            if ($isKnownHoliday) {
                $gap->update(['filled_at' => Carbon::now()]);
                continue;
            }

            if ($timeframe === '1M') {
                // Fetch 1M candles from Zerodha for the full trading-day window
                $filled = $this->fetch1MFromExchange($symbol, $from, $to);
                $totalFilled += $filled;

                if ($filled > 0) {
                    // Aggregate higher TFs for this day using the filled 1M data
                    $this->aggregateAllHigherTFs($symbol->id, $from, $to);
                }

                // Verify day completeness before marking filled. If the day is
                // still below the completeness threshold, leave filled_at=null
                // so the next scan/fill cycle retries.
                if ($this->isDayComplete($symbol->id, '1M', $dayIST)) {
                    $gap->update(['filled_at' => Carbon::now()]);
                    Log::info("NSEGapDetector: Filled {$filled} 1M candles for {$dateKey} — day complete");
                } else {
                    // Not a known holiday and still incomplete — likely a transient
                    // fetch issue (rate limit, network, token). Leave unfilled for retry.
                    Log::warning("NSEGapDetector: {$dateKey} still incomplete after fetch ({$filled} new 1M candles) — will retry next run");
                }
            } else {
                // Higher TFs: aggregate from existing 1M data (rule #10 — never
                // fetch higher TFs directly when 1M can be aggregated).
                $existing1M = Candle::where('symbol_id', $symbol->id)
                    ->where('timeframe', '1M')
                    ->whereBetween('timestamp', [$from->copy()->setTimezone('UTC'), $to->copy()->setTimezone('UTC')])
                    ->count();

                if ($existing1M === 0) {
                    // No 1M data for this day — fetch it first, THEN aggregate
                    $fetched = $this->fetch1MFromExchange($symbol, $from, $to);
                    if ($fetched === 0) {
                        // Still 0 candles from a non-holiday day — leave unfilled for retry
                        Log::warning("NSEGapDetector: {$dateKey} [{$timeframe}] — 0 candles from Zerodha on non-holiday, will retry");
                        continue;
                    }
                    $existing1M = $fetched;
                }

                $aggregated = $this->aggregateTimeframe($symbol->id, $timeframe, $from, $to);
                $totalFilled += $aggregated;

                // Verify target-TF completeness before marking filled
                if ($this->isDayComplete($symbol->id, $timeframe, $dayIST)) {
                    $gap->update(['filled_at' => Carbon::now()]);
                    Log::info("NSEGapDetector: Aggregated {$aggregated} {$timeframe} from {$existing1M} 1M — {$dateKey} complete");
                } else {
                    Log::warning("NSEGapDetector: {$dateKey} [{$timeframe}] still incomplete after aggregation — will retry next run");
                }
            }
        }

        return $totalFilled;
    }

    /**
     * Verify that a trading day has enough candles on a given timeframe to be
     * considered complete (>= COMPLETENESS_THRESHOLD of EXPECTED_CANDLES_PER_DAY).
     * Used to decide whether a gap should be marked filled or retried.
     */
    private function isDayComplete(int $symbolId, string $timeframe, Carbon $dayIST): bool
    {
        $expected = self::EXPECTED_CANDLES_PER_DAY[$timeframe] ?? 1;
        $minCandles = max(1, (int) floor($expected * self::COMPLETENESS_THRESHOLD));

        $dayStartUTC = $dayIST->copy()
            ->setTime(self::MARKET_OPEN_HOUR, self::MARKET_OPEN_MINUTE)
            ->setTimezone('UTC');
        $dayEndUTC = $dayIST->copy()
            ->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE)
            ->setTimezone('UTC');

        $count = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->whereBetween('timestamp', [$dayStartUTC, $dayEndUTC])
            ->count();

        return $count >= $minCandles;
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

        // Candle timestamps are stored in UTC. Convert from/to to UTC before
        // querying so IST-source Carbon instances don't cause a 5.5h window shift
        // (which previously caused aggregation to pick up only a sliver of bars).
        $fromUTC = $from->copy()->setTimezone('UTC')->subMinutes($intervalMinutes);
        $toUTC   = $to->copy()->setTimezone('UTC')->addMinutes($intervalMinutes);

        // Get 1M candles in range (with buffer for bucket alignment)
        $candles1M = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', '1M')
            ->whereBetween('timestamp', [$fromUTC, $toUTC])
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
