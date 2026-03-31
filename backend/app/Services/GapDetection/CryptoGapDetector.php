<?php

declare(strict_types=1);

namespace App\Services\GapDetection;

use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use App\Services\DataSources\BinanceDataSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Crypto-specific gap detection — 24/7 market, no session gaps expected.
 *
 * Uses consecutive-candle approach: any gap > 1.5× interval is flagged.
 * This is the original approach which WORKS for crypto because there
 * are no overnight closures.
 */
class CryptoGapDetector implements GapDetectorInterface
{
    private const TIMEFRAME_MINUTES = [
        '1M' => 1, '5M' => 5, '15M' => 15,
        '1H' => 60, '4H' => 240, '1D' => 1440,
    ];

    public function getMarketType(): string
    {
        return '24/7';
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
            'groupedGaps' => $this->groupGaps($allGaps),
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
        $timelineSegments = [];
        $segStart = $candles->first()->copy();

        for ($i = 1; $i < $candles->count(); $i++) {
            $prev = $candles[$i - 1];
            $curr = $candles[$i];
            $diffMinutes = (int) abs($prev->diffInMinutes($curr));

            if ($diffMinutes > $thresholdMinutes) {
                $gapStart = $prev->copy()->addMinutes($intervalMinutes);
                $missingCandles = max(0, (int) floor($diffMinutes / $intervalMinutes) - 1);

                $timelineSegments[] = ['type' => 'ok', 'start' => $segStart->toIso8601String(), 'end' => $prev->toIso8601String()];
                $timelineSegments[] = ['type' => 'gap', 'start' => $gapStart->toIso8601String(), 'end' => $curr->toIso8601String()];
                $segStart = $curr->copy();

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

        // Trailing gap
        $lastCandle = $candles->last();
        $now = Carbon::now()->utc();
        $trailingMinutes = (int) abs($lastCandle->diffInMinutes($now));

        if ($trailingMinutes > $thresholdMinutes * 2) {
            $gapStart = $lastCandle->copy()->addMinutes($intervalMinutes);
            $gaps[] = [
                'gapType'         => 'trailing',
                'gapStart'        => $gapStart->toIso8601String(),
                'gapEnd'          => $now->toIso8601String(),
                'durationMinutes' => $trailingMinutes,
                'missingCandles'  => max(0, (int) floor($trailingMinutes / $intervalMinutes) - 1),
            ];

            $timelineSegments[] = ['type' => 'ok', 'start' => $segStart->toIso8601String(), 'end' => $lastCandle->toIso8601String()];
            $timelineSegments[] = ['type' => 'gap', 'start' => $gapStart->toIso8601String(), 'end' => $now->toIso8601String()];

            DataGap::create([
                'symbol_id' => $symbol->id,
                'timeframe' => $tf,
                'gap_start' => $gapStart,
                'gap_end'   => $now,
            ]);
        } else {
            $timelineSegments[] = ['type' => 'ok', 'start' => $segStart->toIso8601String(), 'end' => $now->toIso8601String()];
        }

        // Health
        $firstCandle = $candles->first();
        $totalSpanMinutes = max(1, (int) abs($firstCandle->diffInMinutes($now)));
        $gapMinutes = array_sum(array_column($gaps, 'durationMinutes'));
        $healthPct = max(0, min(100, (int) round(100 - ($gapMinutes / $totalSpanMinutes * 100))));

        // Normalize timeline
        $normalizedTimeline = [];
        foreach ($timelineSegments as $seg) {
            $sStart = Carbon::parse($seg['start']);
            $sEnd = Carbon::parse($seg['end']);
            $startPct = $totalSpanMinutes > 0 ? abs($firstCandle->diffInMinutes($sStart)) / $totalSpanMinutes * 100 : 0;
            $widthPct = $totalSpanMinutes > 0 ? abs($sStart->diffInMinutes($sEnd)) / $totalSpanMinutes * 100 : 0;
            if ($widthPct < 0.3) continue;
            $normalizedTimeline[] = [
                'type'     => $seg['type'],
                'startPct' => round(min(100, $startPct), 1),
                'widthPct' => round(max(0.5, min(100, $widthPct)), 1),
                'start'    => $seg['start'],
                'end'      => $seg['end'],
            ];
        }

        return [
            'timeframe'    => $tf,
            'totalCandles' => $totalCandles,
            'gaps'         => $gaps,
            'gapCount'     => count($gaps),
            'healthPct'    => $healthPct,
            'timeline'     => $normalizedTimeline,
        ];
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
        $dataSource = new BinanceDataSource();

        foreach ($gaps as $gap) {
            $from = Carbon::parse($gap->gap_start);
            $to = Carbon::parse($gap->gap_end);

            try {
                if ($timeframe === '1M') {
                    $candles = $dataSource->fetchCandles($symbol->ticker, '1M', $from, $to);

                    if ($candles->isNotEmpty()) {
                        $mapped = $candles->map(fn(array $c) => [...$c, 'symbol_id' => $symbol->id, 'timeframe' => '1M'])->toArray();
                        Candle::upsertCandles($mapped);
                        $totalFetched += $candles->count();
                    }
                } else {
                    // Higher TFs: check for existing 1M data first
                    $existing1M = Candle::where('symbol_id', $symbol->id)
                        ->where('timeframe', '1M')
                        ->whereBetween('timestamp', [$from, $to])
                        ->count();

                    if ($existing1M > 0) {
                        $aggregated = $this->aggregateTimeframe($symbol->id, $timeframe, $from, $to);
                        $totalFetched += $aggregated;
                    } else {
                        // Fetch 1M first
                        $candles = $dataSource->fetchCandles($symbol->ticker, '1M', $from, $to);
                        if ($candles->isNotEmpty()) {
                            $mapped = $candles->map(fn(array $c) => [...$c, 'symbol_id' => $symbol->id, 'timeframe' => '1M'])->toArray();
                            Candle::upsertCandles($mapped);
                            $totalFetched += $candles->count();

                            $aggregated = $this->aggregateTimeframe($symbol->id, $timeframe, $from, $to);
                            $totalFetched += $aggregated;
                        }
                    }
                }

                $gap->update(['filled_at' => Carbon::now()]);
            } catch (\Throwable $e) {
                Log::error("CryptoGapDetector: fill failed — {$e->getMessage()}");
            }
        }

        return $totalFetched;
    }

    private function aggregateTimeframe(int $symbolId, string $timeframe, Carbon $from, Carbon $to): int
    {
        $intervalMinutes = self::TIMEFRAME_MINUTES[$timeframe] ?? 5;

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

        $aggregated = [];
        foreach ($buckets as $bucket) {
            if (empty($bucket['candles'])) continue;

            $first = $bucket['candles'][0];
            $last = end($bucket['candles']);

            $aggregated[] = [
                'symbol_id' => $symbolId,
                'timeframe' => $timeframe,
                'timestamp' => $bucket['timestamp'],
                'open'      => (float) $first->open,
                'high'      => max(array_map(fn($c) => (float) $c->high, $bucket['candles'])),
                'low'       => min(array_map(fn($c) => (float) $c->low, $bucket['candles'])),
                'close'     => (float) $last->close,
                'volume'    => array_sum(array_map(fn($c) => (float) $c->volume, $bucket['candles'])),
            ];
        }

        if (!empty($aggregated)) {
            Candle::upsertCandles($aggregated);
        }

        return count($aggregated);
    }

    private function groupGaps(array $allGaps): array
    {
        if (empty($allGaps)) return [];

        usort($allGaps, fn($a, $b) => strcmp($a['gapStart'], $b['gapStart']));
        $grouped = [];
        $current = null;

        foreach ($allGaps as $gap) {
            if ($current === null) {
                $current = [
                    'gapStart' => $gap['gapStart'], 'gapEnd' => $gap['gapEnd'],
                    'gapType' => $gap['gapType'], 'durationMinutes' => $gap['durationMinutes'],
                    'timeframes' => [$gap['timeframe']], 'missingByTf' => [$gap['timeframe'] => $gap['missingCandles']],
                ];
                continue;
            }

            $currentEnd = Carbon::parse($current['gapEnd']);
            $gapStart = Carbon::parse($gap['gapStart']);

            if (abs($gapStart->diffInMinutes($currentEnd)) < 60 || $gapStart->lte($currentEnd)) {
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
                    'gapStart' => $gap['gapStart'], 'gapEnd' => $gap['gapEnd'],
                    'gapType' => $gap['gapType'], 'durationMinutes' => $gap['durationMinutes'],
                    'timeframes' => [$gap['timeframe']], 'missingByTf' => [$gap['timeframe'] => $gap['missingCandles']],
                ];
            }
        }

        if ($current) $grouped[] = $current;
        return $grouped;
    }
}
