<?php

declare(strict_types=1);

namespace App\Services\LiveFeed;

use App\Events\CandleUpdated;
use App\Jobs\RunEnginesJob;
use App\Models\Candle;
use App\Models\Symbol;
use App\Services\CandleAggregationService;
use App\Services\DataSources\OANDADataSource;
use App\Services\DataSources\YahooDataSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Forex live feed — OANDA REST v20 or Yahoo Finance (fallback).
 *
 * Forex market hours: Sunday 22:00 UTC – Friday 22:00 UTC (continuous).
 * Sessions: Sydney → Tokyo → London → New York (overlapping).
 * Closed: Friday 22:00 UTC to Sunday 22:00 UTC.
 */
class ForexLiveFeed implements LiveFeedInterface
{
    // Forex opens Sunday 22:00 UTC, closes Friday 22:00 UTC
    private const WEEK_OPEN_DAY = Carbon::SUNDAY;
    private const WEEK_OPEN_HOUR = 22;
    private const WEEK_CLOSE_DAY = Carbon::FRIDAY;
    private const WEEK_CLOSE_HOUR = 22;

    public function isMarketOpen(): bool
    {
        $now = Carbon::now('UTC');
        $dayOfWeek = $now->dayOfWeek; // 0=Sunday, 6=Saturday
        $hour = $now->hour;

        // Saturday — always closed
        if ($dayOfWeek === Carbon::SATURDAY) {
            return false;
        }

        // Sunday — open only after 22:00 UTC
        if ($dayOfWeek === Carbon::SUNDAY) {
            return $hour >= self::WEEK_OPEN_HOUR;
        }

        // Friday — open only before 22:00 UTC
        if ($dayOfWeek === Carbon::FRIDAY) {
            return $hour < self::WEEK_CLOSE_HOUR;
        }

        // Monday to Thursday — always open
        return true;
    }

    public function fetchLatest(Symbol $symbol, string $timeframe, int $limit = 10): Collection
    {
        if ($this->isMarketOpen()) {
            try {
                // Forex uses UTC timestamps natively
                $from = Carbon::now('UTC')->subMinutes(15);
                $to = Carbon::now('UTC');

                $dataSource = $this->resolveDataSource($symbol->exchange);
                $fetched = $dataSource->fetchCandles($symbol->ticker, '1M', $from, $to);

                if ($fetched->isNotEmpty()) {
                    // Upsert to DB
                    $mapped = $fetched->map(fn(array $c) => [...$c, 'symbol_id' => $symbol->id])->toArray();
                    Candle::upsertCandles($mapped);

                    // Publish to Redis + broadcast
                    $latestCandle = $fetched->last();
                    $this->publishToRedis($symbol->ticker, '1M', $latestCandle);
                    $this->broadcastCandle($symbol->ticker, '1M', $latestCandle);

                    // Aggregate higher TFs
                    $aggregator = new CandleAggregationService();
                    $aggregatedCandles = $aggregator->aggregateFromOneMinute($symbol->id);

                    foreach ($aggregatedCandles as $tf => $candleData) {
                        if ($candleData) {
                            $this->publishToRedis($symbol->ticker, $tf, $candleData);
                            $this->broadcastCandle($symbol->ticker, $tf, $candleData);
                        }
                    }

                    Log::debug("ForexLiveFeed: {$symbol->ticker} — {$fetched->count()} 1M candles, " .
                        count($aggregatedCandles) . " TFs aggregated");

                    try {
                        RunEnginesJob::dispatch($symbol->id, $timeframe);
                    } catch (\Throwable $je) {
                        Log::debug("ForexLiveFeed: engine dispatch skipped — {$je->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("ForexLiveFeed: fetch failed for {$symbol->ticker} — {$e->getMessage()}");
            }
        } else {
            Log::debug("ForexLiveFeed: {$symbol->ticker} — market closed (weekend), serving from DB");
        }

        return Candle::forSymbol($symbol->id, $timeframe)
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->sortBy('timestamp')
            ->values();
    }

    public function getMarketStatus(): array
    {
        $now = Carbon::now('UTC');
        $open = $this->isMarketOpen();

        // Determine current session
        $session = $this->getCurrentSession($now);

        return [
            'market' => 'forex',
            'open' => $open,
            'session' => $open ? $session : 'Closed (weekend)',
            'timezone' => 'UTC',
            'currentUTC' => $now->format('H:i:s'),
            'message' => $open
                ? "Forex market open — {$session} session active"
                : 'Forex market closed — opens Sunday 22:00 UTC',
            'nextOpen' => $open ? null : $this->getNextOpen($now)->toIso8601String(),
        ];
    }

    public function getMarketType(): string
    {
        return 'forex';
    }

    /**
     * Determine which forex session is currently active.
     */
    private function getCurrentSession(Carbon $now): string
    {
        $hour = $now->hour;

        // Sessions overlap — show the primary one
        if ($hour >= 22 || $hour < 7) {
            return 'Sydney/Tokyo';
        }
        if ($hour >= 7 && $hour < 8) {
            return 'Tokyo/London';
        }
        if ($hour >= 8 && $hour < 13) {
            return 'London';
        }
        if ($hour >= 13 && $hour < 17) {
            return 'London/New York';
        }

        return 'New York'; // 17:00–22:00
    }

    private function getNextOpen(Carbon $now): Carbon
    {
        $next = $now->copy();

        // Find next Sunday
        while ($next->dayOfWeek !== Carbon::SUNDAY) {
            $next->addDay();
        }

        return $next->setTime(self::WEEK_OPEN_HOUR, 0, 0);
    }

    private function resolveDataSource(string $exchange): OANDADataSource|YahooDataSource
    {
        $ex = strtoupper($exchange);

        if (in_array($ex, ['OANDA', 'FOREX'])) {
            return new OANDADataSource();
        }

        return new YahooDataSource();
    }

    private function publishToRedis(string $symbol, string $timeframe, array $candle): void
    {
        $sanitized = str_replace(' ', '-', strtolower($symbol));
        try {
            Redis::publish("candles:{$sanitized}:{$timeframe}", json_encode([
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'candle' => $candle,
                'published_at' => now()->utc()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::debug("ForexLiveFeed: Redis publish skipped — {$e->getMessage()}");
        }
    }

    private function broadcastCandle(string $symbol, string $timeframe, array $candle): void
    {
        try {
            broadcast(new CandleUpdated($symbol, $timeframe, $candle));
        } catch (\Throwable $e) {
            Log::debug("ForexLiveFeed: broadcast skipped — {$e->getMessage()}");
        }
    }
}
