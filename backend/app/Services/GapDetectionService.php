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

    // Market hours per exchange
    private const MARKET_HOURS = [
        'binance' => ['type' => '24/7'],
        'zerodha' => ['type' => 'session', 'open' => '03:45', 'close' => '10:00', 'days' => [1, 2, 3, 4, 5]], // UTC (9:15-15:30 IST)
        'oanda' => ['type' => 'forex', 'open_day' => 0, 'open_hour' => 22, 'close_day' => 5, 'close_hour' => 22], // UTC
        'yahoo' => ['type' => '24/7'], // fallback
    ];

    /**
     * Smart scan: detect ALL gaps including trailing gap (last candle vs now).
     * Returns gaps grouped by timeframe with visual timeline data.
     */
    public function smartScan(Symbol $symbol): array
    {
        $timeframes = ['1M', '5M', '15M', '1H', '4H', '1D'];
        $exchange = $symbol->exchange;
        $results = [];
        $allGaps = [];

        foreach ($timeframes as $tf) {
            $tfResult = $this->scanTimeframe($symbol, $tf, $exchange);
            $results[$tf] = $tfResult;

            foreach ($tfResult['gaps'] as $gap) {
                $allGaps[] = [...$gap, 'timeframe' => $tf];
            }
        }

        // Group overlapping gaps across TFs
        $groupedGaps = $this->groupGaps($allGaps);

        return [
            'symbol' => $symbol->ticker,
            'exchange' => $exchange,
            'marketType' => self::MARKET_HOURS[$exchange]['type'] ?? '24/7',
            'timeframes' => $results,
            'groupedGaps' => $groupedGaps,
            'totalGaps' => count($allGaps),
        ];
    }

    /**
     * Scan a single timeframe for gaps (internal + trailing).
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
            return [
                'timeframe' => $tf,
                'totalCandles' => $totalCandles,
                'gaps' => [],
                'gapCount' => 0,
                'healthPct' => $totalCandles > 0 ? 100 : 0,
                'timeline' => [],
            ];
        }

        $gaps = [];
        $firstCandle = $candles->first();
        $lastCandle = $candles->last();
        $timelineSegments = [];
        $lastGoodEnd = $firstCandle;

        // 1. Internal gaps: scan consecutive candle pairs
        for ($i = 1; $i < $candles->count(); $i++) {
            $prev = $candles[$i - 1];
            $curr = $candles[$i];
            $expectedNext = $prev->copy()->addMinutes($intervalMinutes);
            $diffMinutes = (int) $prev->diffInMinutes($curr);

            // Gap threshold: more than 1.5x the interval
            $threshold = (int) ceil($intervalMinutes * 1.5);

            if ($diffMinutes > $threshold) {
                // Check if this gap falls within market hours
                if ($this->isMarketOpen($expectedNext, $curr, $exchange)) {
                    $missingCandles = (int) floor($diffMinutes / $intervalMinutes) - 1;

                    // Add "good" segment before gap
                    if ($lastGoodEnd->lt($prev)) {
                        $timelineSegments[] = ['type' => 'ok', 'start' => $lastGoodEnd->toIso8601String(), 'end' => $prev->toIso8601String()];
                    }

                    // Add gap segment
                    $timelineSegments[] = [
                        'type' => 'gap',
                        'start' => $expectedNext->toIso8601String(),
                        'end' => $curr->toIso8601String(),
                    ];

                    $gaps[] = [
                        'gapType' => 'internal',
                        'gapStart' => $expectedNext->toIso8601String(),
                        'gapEnd' => $curr->toIso8601String(),
                        'durationMinutes' => $diffMinutes,
                        'missingCandles' => $missingCandles,
                    ];

                    // Persist to data_gaps table
                    DataGap::firstOrCreate([
                        'symbol_id' => $symbol->id,
                        'timeframe' => $tf,
                        'gap_start' => $expectedNext,
                        'gap_end' => $curr,
                    ]);

                    $lastGoodEnd = $curr;
                }
            }
        }

        // 2. Trailing gap: last candle vs NOW
        $now = Carbon::now()->utc();
        $lastCandleTime = $lastCandle->copy();
        $trailingMinutes = (int) $lastCandleTime->diffInMinutes($now);
        $trailingThreshold = (int) ceil($intervalMinutes * 2);

        if ($trailingMinutes > $trailingThreshold && $this->isMarketOpen($lastCandleTime, $now, $exchange)) {
            $missingCandles = (int) floor($trailingMinutes / $intervalMinutes) - 1;
            $trailingStart = $lastCandleTime->copy()->addMinutes($intervalMinutes);

            $gaps[] = [
                'gapType' => 'trailing',
                'gapStart' => $trailingStart->toIso8601String(),
                'gapEnd' => $now->toIso8601String(),
                'durationMinutes' => $trailingMinutes,
                'missingCandles' => $missingCandles,
            ];

            DataGap::firstOrCreate([
                'symbol_id' => $symbol->id,
                'timeframe' => $tf,
                'gap_start' => $trailingStart,
                'gap_end' => $now,
            ]);

            // Timeline: good segment then trailing gap
            if ($lastGoodEnd->lt($lastCandleTime)) {
                $timelineSegments[] = ['type' => 'ok', 'start' => $lastGoodEnd->toIso8601String(), 'end' => $lastCandleTime->toIso8601String()];
            }
            $timelineSegments[] = ['type' => 'gap', 'start' => $trailingStart->toIso8601String(), 'end' => $now->toIso8601String()];
        } else {
            // No trailing gap — last segment is OK until now
            if ($lastGoodEnd->lt($now)) {
                $timelineSegments[] = ['type' => 'ok', 'start' => $lastGoodEnd->toIso8601String(), 'end' => $now->toIso8601String()];
            }
        }

        // Calculate health as percentage of data coverage
        $totalSpanMinutes = max(1, (int) $firstCandle->diffInMinutes($now));
        $gapMinutes = array_sum(array_column($gaps, 'durationMinutes'));
        $healthPct = max(0, min(100, (int) round(100 - ($gapMinutes / $totalSpanMinutes * 100))));

        // Build normalized timeline (0-100% for frontend rendering)
        $normalizedTimeline = [];
        foreach ($timelineSegments as $seg) {
            $segStart = Carbon::parse($seg['start']);
            $segEnd = Carbon::parse($seg['end']);
            $startPct = max(0, min(100, $firstCandle->diffInMinutes($segStart) / $totalSpanMinutes * 100));
            $endPct = max(0, min(100, $firstCandle->diffInMinutes($segEnd) / $totalSpanMinutes * 100));
            $normalizedTimeline[] = [
                'type' => $seg['type'],
                'startPct' => round($startPct, 1),
                'widthPct' => round(max(0.5, $endPct - $startPct), 1),
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
            'firstCandle' => $firstCandle->toIso8601String(),
            'lastCandle' => $lastCandle->toIso8601String(),
        ];
    }

    /**
     * Check if market was open during a time range (for gap filtering).
     */
    private function isMarketOpen(Carbon $from, Carbon $to, string $exchange): bool
    {
        $config = self::MARKET_HOURS[$exchange] ?? ['type' => '24/7'];

        if ($config['type'] === '24/7') {
            return true; // Crypto — every gap is real
        }

        if ($config['type'] === 'forex') {
            // Forex is closed Sat-Sun (Fri 10PM UTC to Sun 10PM UTC)
            $isSaturday = $from->dayOfWeek === 6;
            $isSunday = $from->dayOfWeek === 0 && $from->hour < $config['open_hour'];
            $isFridayLate = $from->dayOfWeek === 5 && $from->hour >= $config['close_hour'];

            return ! ($isSaturday || $isSunday || $isFridayLate);
        }

        if ($config['type'] === 'session') {
            // Session-based (NSE) — check if both from and to are within trading days
            $openParts = explode(':', $config['open']);
            $closeParts = explode(':', $config['close']);
            $openHour = (int) $openParts[0];
            $openMin = (int) ($openParts[1] ?? 0);
            $closeHour = (int) $closeParts[0];
            $closeMin = (int) ($closeParts[1] ?? 0);

            // Check if the gap spans a non-trading period
            $fromInSession = in_array($from->dayOfWeek, $config['days'])
                && ($from->hour > $openHour || ($from->hour === $openHour && $from->minute >= $openMin))
                && ($from->hour < $closeHour || ($from->hour === $closeHour && $from->minute <= $closeMin));

            return $fromInSession;
        }

        return true;
    }

    /**
     * Group gaps that overlap in time across multiple timeframes.
     */
    private function groupGaps(array $allGaps): array
    {
        if (empty($allGaps)) {
            return [];
        }

        // Sort by gap_start
        usort($allGaps, fn ($a, $b) => strcmp($a['gapStart'], $b['gapStart']));

        $grouped = [];
        $current = null;

        foreach ($allGaps as $gap) {
            $gapStart = Carbon::parse($gap['gapStart']);
            $gapEnd = Carbon::parse($gap['gapEnd']);

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

            // If this gap overlaps or is within 30 min of the current group
            if ($gapStart->diffInMinutes($currentEnd) < 30) {
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
     * Old detect method (kept for backward compat).
     */
    public function detect(Symbol $symbol, string $timeframe): Collection
    {
        $result = $this->scanTimeframe($symbol, $timeframe, $symbol->exchange);

        return DataGap::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->unfilled()
            ->get();
    }

    /**
     * Fill gaps by fetching candles from exchange.
     */
    public function fill(Symbol $symbol, string $timeframe, Collection $gaps): int
    {
        $dataSource = $this->resolveDataSource($symbol->exchange);
        $totalFilled = 0;

        foreach ($gaps as $gap) {
            if ($gap->filled_at) {
                continue;
            }

            try {
                $from = Carbon::parse($gap->gap_start);
                $to = Carbon::parse($gap->gap_end);

                // For higher TFs, fetch 1M and let aggregation handle it
                $fetchTf = $timeframe;
                if (in_array($timeframe, ['5M', '15M', '1H', '4H', '1D'])) {
                    $fetchTf = '1M';
                }

                $candles = $dataSource->fetchCandles($symbol->ticker, $fetchTf, $from, $to);

                if ($candles->isNotEmpty()) {
                    $mapped = $candles->map(fn (array $c) => [...$c, 'symbol_id' => $symbol->id])->toArray();
                    Candle::upsertCandles($mapped);
                    $totalFilled += $candles->count();

                    // If we fetched 1M, aggregate to higher TFs
                    if ($fetchTf === '1M' && $timeframe !== '1M') {
                        $aggregator = new CandleAggregationService();
                        $aggregator->aggregateFromOneMinute($symbol->id);
                    }

                    Log::info("Gap filled: {$symbol->ticker} [{$timeframe}] {$gap->gap_start} → {$gap->gap_end}: {$candles->count()} candles");
                }

                $gap->update(['filled_at' => Carbon::now()]);
            } catch (\Throwable $e) {
                Log::warning("Gap fill failed for {$symbol->ticker} [{$timeframe}]: {$e->getMessage()}");
            }
        }

        return $totalFilled;
    }

    private function resolveDataSource(string $exchange): DataSourceInterface
    {
        return match ($exchange) {
            'binance' => new BinanceDataSource(),
            'zerodha' => new ZerodhaDataSource(),
            'oanda' => new OANDADataSource(),
            'yahoo' => new YahooDataSource(),
            default => throw new \RuntimeException("Unsupported exchange: {$exchange}"),
        };
    }
}
