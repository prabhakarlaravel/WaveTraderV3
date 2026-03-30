<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use App\Services\DataSources\BinanceDataSource;
use App\Services\DataSources\DataSourceInterface;
use App\Services\DataSources\OANDADataSource;
use App\Services\DataSources\YahooDataSource;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GapDetectionService
{
    private const TIMEFRAME_MINUTES = [
        '1M' => 1, '5M' => 5, '15M' => 15,
        '1H' => 60, '4H' => 240, '1D' => 1440,
    ];

    /**
     * Smart scan: detect ALL gaps across all TFs for a symbol.
     * For higher TFs (5M+), checks if gap can be filled from existing 1M data.
     */
    public function smartScan(Symbol $symbol): array
    {
        $timeframes = ['1M', '5M', '15M', '1H', '4H', '1D'];
        $exchange = $symbol->exchange;
        $results = [];
        $allGaps = [];

        // First, clear stale gap records for this symbol
        DataGap::where('symbol_id', $symbol->id)->delete();

        foreach ($timeframes as $tf) {
            $tfResult = $this->scanTimeframe($symbol, $tf, $exchange);
            $results[$tf] = $tfResult;

            foreach ($tfResult['gaps'] as $gap) {
                $allGaps[] = [...$gap, 'timeframe' => $tf];
            }
        }

        $groupedGaps = $this->groupGaps($allGaps);

        return [
            'symbol' => $symbol->ticker,
            'exchange' => $exchange,
            'marketType' => $this->getMarketType($exchange),
            'timeframes' => $results,
            'groupedGaps' => $groupedGaps,
            'totalGaps' => count($allGaps),
        ];
    }

    /**
     * Scan a single timeframe for gaps.
     */
    private function scanTimeframe(Symbol $symbol, string $tf, string $exchange): array
    {
        $intervalMinutes = self::TIMEFRAME_MINUTES[$tf] ?? 1;

        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $tf)
            ->orderBy('timestamp')
            ->pluck('timestamp');

        $totalCandles = $candles->count();

        if ($totalCandles < 2) {
            // If zero data, report as 1 big gap covering the full expected range
            $noDataGapCount = $totalCandles === 0 ? 1 : 0;
            $noDataGaps = [];
            if ($totalCandles === 0) {
                $rangeStart = Carbon::now()->subMonths(3)->startOfDay();
                $rangeEnd = Carbon::now();
                $noDataGaps[] = [
                    'gapType' => 'no_data',
                    'gapStart' => $rangeStart->toIso8601String(),
                    'gapEnd' => $rangeEnd->toIso8601String(),
                    'durationMinutes' => (int) abs($rangeStart->diffInMinutes($rangeEnd)),
                    'missingCandles' => 0, // unknown
                ];
            }
            return [
                'timeframe' => $tf,
                'totalCandles' => $totalCandles,
                'gaps' => $noDataGaps,
                'gapCount' => $noDataGapCount,
                'healthPct' => $totalCandles === 0 ? 0 : 0,
                'timeline' => [],
            ];
        }

        $gaps = [];
        $firstCandle = $candles->first();
        $lastCandle = $candles->last();
        $now = Carbon::now()->utc();
        $timelineSegments = [];
        $segStart = $firstCandle->copy();

        // Threshold: a gap is when the next candle is more than 1.5x the interval away
        $thresholdMinutes = (int) ceil($intervalMinutes * 1.5);

        // 1. Scan consecutive candle pairs for internal gaps
        for ($i = 1; $i < $candles->count(); $i++) {
            $prev = $candles[$i - 1];
            $curr = $candles[$i];
            $diffMinutes = (int) abs($prev->diffInMinutes($curr));

            if ($diffMinutes > $thresholdMinutes) {
                // Check market hours
                if (! $this->isMarketClosed($prev, $curr, $exchange)) {
                    $gapStart = $prev->copy()->addMinutes($intervalMinutes);
                    $missingCandles = max(0, (int) floor($diffMinutes / $intervalMinutes) - 1);

                    // Timeline: OK segment before gap
                    $timelineSegments[] = ['type' => 'ok', 'start' => $segStart->toIso8601String(), 'end' => $prev->toIso8601String()];
                    // Gap segment
                    $timelineSegments[] = ['type' => 'gap', 'start' => $gapStart->toIso8601String(), 'end' => $curr->toIso8601String()];
                    $segStart = $curr->copy();

                    $gaps[] = [
                        'gapType' => 'internal',
                        'gapStart' => $gapStart->toIso8601String(),
                        'gapEnd' => $curr->toIso8601String(),
                        'durationMinutes' => $diffMinutes,
                        'missingCandles' => $missingCandles,
                    ];

                    // Store in DB
                    DataGap::create([
                        'symbol_id' => $symbol->id,
                        'timeframe' => $tf,
                        'gap_start' => $gapStart,
                        'gap_end' => $curr,
                    ]);
                }
            }
        }

        // 2. Trailing gap: last candle vs now
        $trailingMinutes = (int) abs($lastCandle->diffInMinutes($now));
        if ($trailingMinutes > $thresholdMinutes * 2 && ! $this->isMarketClosed($lastCandle, $now, $exchange)) {
            $gapStart = $lastCandle->copy()->addMinutes($intervalMinutes);
            $missingCandles = max(0, (int) floor($trailingMinutes / $intervalMinutes) - 1);

            // Timeline: OK segment then trailing gap
            $timelineSegments[] = ['type' => 'ok', 'start' => $segStart->toIso8601String(), 'end' => $lastCandle->toIso8601String()];
            $timelineSegments[] = ['type' => 'gap', 'start' => $gapStart->toIso8601String(), 'end' => $now->toIso8601String()];

            $gaps[] = [
                'gapType' => 'trailing',
                'gapStart' => $gapStart->toIso8601String(),
                'gapEnd' => $now->toIso8601String(),
                'durationMinutes' => $trailingMinutes,
                'missingCandles' => $missingCandles,
            ];

            DataGap::create([
                'symbol_id' => $symbol->id,
                'timeframe' => $tf,
                'gap_start' => $gapStart,
                'gap_end' => $now,
            ]);
        } else {
            // No trailing gap — close the last OK segment
            $timelineSegments[] = ['type' => 'ok', 'start' => $segStart->toIso8601String(), 'end' => $now->toIso8601String()];
        }

        // Calculate health
        $totalSpanMinutes = max(1, (int) abs($firstCandle->diffInMinutes($now)));
        $gapMinutes = array_sum(array_column($gaps, 'durationMinutes'));
        $healthPct = max(0, min(100, (int) round(100 - ($gapMinutes / $totalSpanMinutes * 100))));

        // Normalize timeline to 0-100%
        $normalizedTimeline = [];
        foreach ($timelineSegments as $seg) {
            $sStart = Carbon::parse($seg['start']);
            $sEnd = Carbon::parse($seg['end']);
            $startPct = $totalSpanMinutes > 0 ? abs($firstCandle->diffInMinutes($sStart)) / $totalSpanMinutes * 100 : 0;
            $widthPct = $totalSpanMinutes > 0 ? abs($sStart->diffInMinutes($sEnd)) / $totalSpanMinutes * 100 : 0;
            if ($widthPct < 0.3) {
                continue;
            } // skip tiny segments
            $normalizedTimeline[] = [
                'type' => $seg['type'],
                'startPct' => round(min(100, $startPct), 1),
                'widthPct' => round(max(0.5, min(100, $widthPct)), 1),
                'start' => $seg['start'],
                'end' => $seg['end'],
            ];
        }

        return [
            'timeframe' => $tf,
            'totalCandles' => $totalCandles,
            'gaps' => $gaps,
            'gapCount' => count($gaps),
            'healthPct' => $healthPct,
            'timeline' => $normalizedTimeline,
        ];
    }

    /**
     * Check if market was closed during this period (so the gap is expected).
     */
    private function isMarketClosed(Carbon $from, Carbon $to, string $exchange): bool
    {
        $ex = strtoupper($exchange);

        // Crypto markets are 24/7 — no expected closures
        if ($ex === 'BINANCE') {
            return false;
        }

        // Forex: closed Sat + most of Sunday
        if ($ex === 'OANDA') {
            $fromDay = $from->dayOfWeek;
            if ($fromDay === 6) {
                return true;
            } // Saturday
            if ($fromDay === 5 && $from->hour >= 22) {
                return true;
            } // Friday after 10pm
            if ($fromDay === 0 && $to->dayOfWeek === 0 && $to->hour < 22) {
                return true;
            } // Sunday before 10pm
        }

        // NSE/BSE: only open Mon-Fri 3:45-10:00 UTC (9:15-15:30 IST)
        if (in_array($ex, ['ZERODHA', 'NSE', 'BSE', 'NFO', 'MCX'])) {
            $fromDay = $from->dayOfWeek;
            if ($fromDay === 0 || $fromDay === 6) {
                return true;
            } // Weekend
            if ($from->hour < 3 || $from->hour >= 10) {
                return true;
            } // Outside session
        }

        return false;
    }

    /**
     * Fill gaps by fetching missing candles from exchange.
     * Strategy:
     * - For 1M: fetch directly from exchange API
     * - For higher TFs: try to aggregate from existing 1M data first,
     *   if 1M also has gaps, fetch 1M then aggregate
     */
    public function fill(Symbol $symbol, string $timeframe, ?Collection $gaps = null): int
    {
        if ($gaps === null) {
            $gaps = DataGap::where('symbol_id', $symbol->id)
                ->where('timeframe', $timeframe)
                ->unfilled()
                ->get();
        }

        if ($gaps->isEmpty()) {
            Log::info("No unfilled gaps for {$symbol->ticker} [{$timeframe}]");

            return 0;
        }

        $totalFetched = 0;

        foreach ($gaps as $gap) {
            if ($gap->filled_at) {
                continue;
            }

            $from = Carbon::parse($gap->gap_start);
            $to = Carbon::parse($gap->gap_end);

            Log::info("Filling gap: {$symbol->ticker} [{$timeframe}] {$from} → {$to}");

            // Strategy: always fetch 1M from exchange, then aggregate
            $fetched = $this->fetchAndStore($symbol, '1M', $from, $to);
            $totalFetched += $fetched;

            // If this is a higher TF gap, aggregate from the fresh 1M data
            if ($timeframe !== '1M' && $fetched > 0) {
                $aggregated = $this->aggregateTimeframe($symbol->id, $timeframe, $from, $to);
                $totalFetched += $aggregated;
                Log::info("Aggregated {$aggregated} {$timeframe} candles from 1M for {$symbol->ticker}");
            }

            // Only mark as filled if we actually got candles
            if ($fetched > 0) {
                $gap->update(['filled_at' => Carbon::now()]);
                Log::info("Gap filled: {$fetched} candles for {$symbol->ticker} [{$timeframe}]");
            } else {
                Log::warning("Gap fill returned 0 candles for {$symbol->ticker} [{$timeframe}] {$from} → {$to}");
            }
        }

        return $totalFetched;
    }

    /**
     * Fetch candles from exchange and upsert to DB.
     */
    private function fetchAndStore(Symbol $symbol, string $timeframe, Carbon $from, Carbon $to): int
    {
        try {
            $dataSource = $this->resolveDataSource($symbol->exchange);
            $candles = $dataSource->fetchCandles($symbol->ticker, $timeframe, $from, $to);

            if ($candles->isEmpty()) {
                return 0;
            }

            $mapped = $candles->map(fn (array $c) => [
                ...$c,
                'symbol_id' => $symbol->id,
                'timeframe' => $timeframe,
            ])->toArray();

            Candle::upsertCandles($mapped);

            return $candles->count();
        } catch (\Throwable $e) {
            Log::error("fetchAndStore failed: {$symbol->ticker} [{$timeframe}]: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Aggregate a higher timeframe from 1M base data for a specific time range.
     */
    private function aggregateTimeframe(int $symbolId, string $timeframe, Carbon $from, Carbon $to): int
    {
        $intervalMinutes = self::TIMEFRAME_MINUTES[$timeframe] ?? 5;

        // Get all 1M candles in the range
        $candles1M = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', '1M')
            ->whereBetween('timestamp', [$from->copy()->subMinutes($intervalMinutes), $to->copy()->addMinutes($intervalMinutes)])
            ->orderBy('timestamp')
            ->get();

        if ($candles1M->isEmpty()) {
            return 0;
        }

        // Group into buckets
        $buckets = [];
        foreach ($candles1M as $c) {
            $ts = Carbon::parse($c->timestamp);
            $bucketMinute = (int) (floor($ts->hour * 60 + $ts->minute) / $intervalMinutes) * $intervalMinutes;
            $bucketTime = $ts->copy()->startOfDay()->addMinutes($bucketMinute);
            $key = $bucketTime->format('Y-m-d H:i:s');

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['candles' => [], 'timestamp' => $key];
            }
            $buckets[$key]['candles'][] = $c;
        }

        // Aggregate each bucket into OHLCV
        $aggregated = [];
        foreach ($buckets as $key => $bucket) {
            if (empty($bucket['candles'])) {
                continue;
            }

            $first = $bucket['candles'][0];
            $last = end($bucket['candles']);
            $high = max(array_map(fn ($c) => (float) $c->high, $bucket['candles']));
            $low = min(array_map(fn ($c) => (float) $c->low, $bucket['candles']));
            $volume = array_sum(array_map(fn ($c) => (float) $c->volume, $bucket['candles']));

            $aggregated[] = [
                'symbol_id' => $symbolId,
                'timeframe' => $timeframe,
                'timestamp' => $bucket['timestamp'],
                'open' => (float) $first->open,
                'high' => $high,
                'low' => $low,
                'close' => (float) $last->close,
                'volume' => $volume,
            ];
        }

        if (! empty($aggregated)) {
            Candle::upsertCandles($aggregated);
        }

        return count($aggregated);
    }

    /**
     * Group overlapping gaps across multiple timeframes.
     */
    private function groupGaps(array $allGaps): array
    {
        if (empty($allGaps)) {
            return [];
        }

        usort($allGaps, fn ($a, $b) => strcmp($a['gapStart'], $b['gapStart']));

        $grouped = [];
        $current = null;

        foreach ($allGaps as $gap) {
            if ($current === null) {
                $current = [
                    'gapStart' => $gap['gapStart'],
                    'gapEnd' => $gap['gapEnd'],
                    'gapType' => $gap['gapType'],
                    'durationMinutes' => $gap['durationMinutes'],
                    'timeframes' => [$gap['timeframe']],
                    'missingByTf' => [$gap['timeframe'] => $gap['missingCandles']],
                ];
                continue;
            }

            $currentEnd = Carbon::parse($current['gapEnd']);
            $gapStart = Carbon::parse($gap['gapStart']);

            // Merge if overlapping or within 60 min
            if (abs($gapStart->diffInMinutes($currentEnd)) < 60 || $gapStart->lte($currentEnd)) {
                $gapEnd = Carbon::parse($gap['gapEnd']);
                if ($gapEnd->gt($currentEnd)) {
                    $current['gapEnd'] = $gap['gapEnd'];
                    $current['durationMinutes'] = max($current['durationMinutes'], $gap['durationMinutes']);
                }
                if (! in_array($gap['timeframe'], $current['timeframes'])) {
                    $current['timeframes'][] = $gap['timeframe'];
                }
                $current['missingByTf'][$gap['timeframe']] = $gap['missingCandles'];
                if ($gap['gapType'] === 'trailing') {
                    $current['gapType'] = 'trailing';
                }
            } else {
                $grouped[] = $current;
                $current = [
                    'gapStart' => $gap['gapStart'],
                    'gapEnd' => $gap['gapEnd'],
                    'gapType' => $gap['gapType'],
                    'durationMinutes' => $gap['durationMinutes'],
                    'timeframes' => [$gap['timeframe']],
                    'missingByTf' => [$gap['timeframe'] => $gap['missingCandles']],
                ];
            }
        }

        if ($current) {
            $grouped[] = $current;
        }

        return $grouped;
    }

    /**
     * Old detect method (backward compat).
     */
    public function detect(Symbol $symbol, string $timeframe): Collection
    {
        // Trigger a scan for this specific TF
        $this->scanTimeframe($symbol, $timeframe, $symbol->exchange);

        return DataGap::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->unfilled()
            ->get();
    }

    private function getMarketType(string $exchange): string
    {
        return match (strtoupper($exchange)) {
            'BINANCE' => '24/7',
            'ZERODHA', 'NSE', 'BSE', 'NFO', 'MCX' => 'NSE Session',
            'OANDA' => 'Forex',
            default => 'Unknown',
        };
    }

    private function resolveDataSource(string $exchange): DataSourceInterface
    {
        return match (strtoupper($exchange)) {
            'BINANCE'  => new BinanceDataSource(),
            'ZERODHA'  => new ZerodhaDataSource(),
            'NSE', 'BSE', 'NFO', 'MCX' => new ZerodhaDataSource(),
            'OANDA'    => new OANDADataSource(),
            'YAHOO'    => new YahooDataSource(),
            default    => throw new \RuntimeException("Unsupported exchange: {$exchange}"),
        };
    }
}
