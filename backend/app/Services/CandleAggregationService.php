<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Derives higher timeframe candles from 1M base candles (CLAUDE.md rule #10).
 * After each 1M fetch, aggregates into 5M, 15M, 1H, 4H, 1D.
 */
class CandleAggregationService
{
    private const AGGREGATION_MAP = [
        '5M' => 5,
        '15M' => 15,
        '1H' => 60,
        '4H' => 240,
        '1D' => 1440,
    ];

    /**
     * Aggregate 1M candles into all higher timeframes for a symbol.
     * Only aggregates the most recent window to stay fast on every 30s cycle.
     *
     * @return array Map of timeframe => latest aggregated candle
     */
    public function aggregateFromOneMinute(int $symbolId): array
    {
        $results = [];

        foreach (self::AGGREGATION_MAP as $targetTf => $minutes) {
            $latestCandle = $this->aggregateTimeframe($symbolId, $targetTf, $minutes);
            if ($latestCandle) {
                $results[$targetTf] = $latestCandle;
            }
        }

        return $results;
    }

    /**
     * Aggregate 1M candles into a single higher timeframe.
     * Fetches only the most recent bucket window of 1M candles to keep it fast.
     */
    private function aggregateTimeframe(int $symbolId, string $targetTf, int $bucketMinutes): ?array
    {
        // Determine the current bucket start time
        $now = Carbon::now();
        $bucketStart = $this->getBucketStart($now, $bucketMinutes);

        // For the current in-progress bucket AND the previous completed one
        $fetchFrom = $bucketStart->copy()->subMinutes($bucketMinutes);

        $oneMinCandles = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', '1M')
            ->where('timestamp', '>=', $fetchFrom)
            ->orderBy('timestamp')
            ->get();

        if ($oneMinCandles->isEmpty()) {
            return null;
        }

        // Group into buckets
        $buckets = $oneMinCandles->groupBy(function ($candle) use ($bucketMinutes) {
            return $this->getBucketStart(Carbon::parse($candle->timestamp)->utc(), $bucketMinutes)
                ->format('Y-m-d H:i:sP');
        });

        $latestCandle = null;

        foreach ($buckets as $bucketTs => $candles) {
            if ($candles->isEmpty()) {
                continue;
            }

            $aggregated = [
                'symbol_id' => $symbolId,
                'timeframe' => $targetTf,
                'timestamp' => $bucketTs,
                'open' => $candles->first()->open,
                'high' => $candles->max('high'),
                'low' => $candles->min('low'),
                'close' => $candles->last()->close,
                'volume' => $candles->sum('volume'),
            ];

            // Upsert (rule #2)
            Candle::upsertCandles([$aggregated]);
            $latestCandle = $aggregated;
        }

        return $latestCandle;
    }

    /**
     * Calculate the bucket start timestamp for a given time and bucket size.
     * e.g., for 5M bucket: 10:07 → 10:05, 14:23 → 14:20
     * e.g., for 4H bucket: 14:23 → 12:00
     * e.g., for 1D bucket: any time → 00:00 of that day
     */
    private function getBucketStart(Carbon $time, int $bucketMinutes): Carbon
    {
        if ($bucketMinutes >= 1440) {
            // Daily: start of day
            return $time->copy()->startOfDay();
        }

        $minuteOfDay = $time->hour * 60 + $time->minute;
        $bucketMinuteOfDay = (int) floor($minuteOfDay / $bucketMinutes) * $bucketMinutes;
        $bucketHour = (int) floor($bucketMinuteOfDay / 60);
        $bucketMinute = $bucketMinuteOfDay % 60;

        return $time->copy()->setTime($bucketHour, $bucketMinute, 0);
    }
}
