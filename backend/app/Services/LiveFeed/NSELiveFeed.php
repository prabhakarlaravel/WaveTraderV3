<?php

declare(strict_types=1);

namespace App\Services\LiveFeed;

use App\Events\CandleUpdated;
use App\Jobs\RunEnginesJob;
use App\Models\Candle;
use App\Models\Symbol;
use App\Services\CandleAggregationService;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * NSE/BSE live feed — Indian equity markets via Zerodha KiteConnect.
 *
 * Market hours: 09:15 IST – 15:30 IST, Monday–Friday (excluding holidays).
 * Pre-open session: 09:00 – 09:15 IST (we start fetching at 09:00).
 *
 * IMPORTANT: Zerodha API expects IST timestamps, NOT UTC.
 * All time calculations use Asia/Kolkata timezone.
 */
class NSELiveFeed implements LiveFeedInterface
{
    private const TIMEZONE = 'Asia/Kolkata';
    private const MARKET_OPEN_HOUR = 9;
    private const MARKET_OPEN_MINUTE = 0;  // Pre-open starts at 09:00
    private const MARKET_CLOSE_HOUR = 15;
    private const MARKET_CLOSE_MINUTE = 30;

    // Buffer: fetch a few minutes after close to capture the closing candle
    private const POST_CLOSE_BUFFER_MINUTES = 5;

    private ZerodhaDataSource $dataSource;

    public function __construct()
    {
        $this->dataSource = new ZerodhaDataSource();
    }

    public function isMarketOpen(): bool
    {
        $now = Carbon::now(self::TIMEZONE);

        // Weekends — NSE is closed
        if ($now->isWeekend()) {
            return false;
        }

        // Check if within market hours (09:00 to 15:35 IST — with post-close buffer)
        $marketOpen = $now->copy()->setTime(self::MARKET_OPEN_HOUR, self::MARKET_OPEN_MINUTE);
        $marketClose = $now->copy()->setTime(
            self::MARKET_CLOSE_HOUR,
            self::MARKET_CLOSE_MINUTE + self::POST_CLOSE_BUFFER_MINUTES
        );

        return $now->between($marketOpen, $marketClose);
    }

    public function fetchLatest(Symbol $symbol, string $timeframe, int $limit = 10): Collection
    {
        $now = Carbon::now(self::TIMEZONE);

        if ($this->isMarketOpen()) {
            try {
                // CRITICAL: Zerodha expects IST timestamps, NOT UTC
                // Fetch last 15 minutes in IST
                $from = $now->copy()->subMinutes(15);
                $to = $now->copy();

                // Clamp 'from' to market open if before 09:15
                $marketStart = $now->copy()->setTime(9, 15);
                if ($from->lt($marketStart)) {
                    $from = $marketStart;
                }

                $fetched = $this->dataSource->fetchCandles($symbol->ticker, '1M', $from, $to);

                if ($fetched->isNotEmpty()) {
                    // Upsert to DB
                    $mapped = $fetched->map(fn(array $c) => [...$c, 'symbol_id' => $symbol->id])->toArray();
                    Candle::upsertCandles($mapped);

                    // Publish to Redis + broadcast via Reverb
                    $latestCandle = $fetched->last();
                    $this->publishToRedis($symbol->ticker, '1M', $latestCandle);
                    $this->broadcastCandle($symbol->ticker, '1M', $latestCandle);

                    // Aggregate higher TFs from 1M base
                    $aggregator = new CandleAggregationService();
                    $aggregatedCandles = $aggregator->aggregateFromOneMinute($symbol->id);

                    foreach ($aggregatedCandles as $tf => $candleData) {
                        if ($candleData) {
                            $this->publishToRedis($symbol->ticker, $tf, $candleData);
                            $this->broadcastCandle($symbol->ticker, $tf, $candleData);
                        }
                    }

                    Log::debug("NSELiveFeed: {$symbol->ticker} — {$fetched->count()} 1M candles, " .
                        count($aggregatedCandles) . " TFs aggregated [IST: {$now->format('H:i:s')}]");

                    // Dispatch engine run
                    try {
                        RunEnginesJob::dispatch($symbol->id, $timeframe);
                    } catch (\Throwable $je) {
                        Log::debug("NSELiveFeed: engine dispatch skipped — {$je->getMessage()}");
                    }
                } else {
                    Log::debug("NSELiveFeed: {$symbol->ticker} — 0 candles from Zerodha " .
                        "[IST window: {$from->format('Y-m-d H:i:s')} to {$to->format('Y-m-d H:i:s')}]");
                }
            } catch (\Throwable $e) {
                Log::warning("NSELiveFeed: fetch failed for {$symbol->ticker} — {$e->getMessage()}");
            }
        } else {
            Log::debug("NSELiveFeed: {$symbol->ticker} — market closed [IST: {$now->format('H:i D')}], serving from DB");
        }

        // Always return last N candles from DB (works both during and outside market hours)
        return Candle::forSymbol($symbol->id, $timeframe)
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->sortBy('timestamp')
            ->values();
    }

    public function getMarketStatus(): array
    {
        $now = Carbon::now(self::TIMEZONE);
        $open = $this->isMarketOpen();

        $marketOpen = $now->copy()->setTime(self::MARKET_OPEN_HOUR, 15); // Actual trading starts 09:15
        $marketClose = $now->copy()->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE);

        $message = $open
            ? 'NSE market is open — live data active'
            : $this->getClosedMessage($now);

        $nextOpen = null;
        if (! $open) {
            $nextOpen = $this->getNextMarketOpen($now)->toIso8601String();
        }

        return [
            'market' => 'nse',
            'open' => $open,
            'session' => '09:15–15:30 IST',
            'timezone' => self::TIMEZONE,
            'currentIST' => $now->format('H:i:s'),
            'marketOpen' => $marketOpen->format('H:i'),
            'marketClose' => $marketClose->format('H:i'),
            'message' => $message,
            'nextOpen' => $nextOpen,
            'lastTradingDay' => $this->getLastTradingDay($now)->format('Y-m-d'),
        ];
    }

    public function getMarketType(): string
    {
        return 'nse';
    }

    /**
     * Get a human-readable message for when the market is closed.
     */
    private function getClosedMessage(Carbon $now): string
    {
        if ($now->isWeekend()) {
            return "NSE closed — weekend. Opens Monday 09:15 IST.";
        }

        $hour = $now->hour;
        $minute = $now->minute;

        if ($hour < self::MARKET_OPEN_HOUR || ($hour === self::MARKET_OPEN_HOUR && $minute < 15)) {
            return "NSE pre-market — opens at 09:15 IST today.";
        }

        return "NSE closed for the day — last session: 09:15–15:30 IST.";
    }

    /**
     * Calculate when the market next opens.
     */
    private function getNextMarketOpen(Carbon $now): Carbon
    {
        $next = $now->copy();

        if ($next->hour >= self::MARKET_CLOSE_HOUR) {
            $next->addDay();
        }

        // Skip weekends
        while ($next->isWeekend()) {
            $next->addDay();
        }

        return $next->setTime(9, 15, 0);
    }

    /**
     * Get the last trading day (for serving DB candles outside hours).
     */
    private function getLastTradingDay(Carbon $now): Carbon
    {
        $day = $now->copy();

        // If before market open today, go to previous day
        $marketOpen = $day->copy()->setTime(self::MARKET_OPEN_HOUR, 15);
        if ($day->lt($marketOpen)) {
            $day->subDay();
        }

        // Skip weekends backwards
        while ($day->isWeekend()) {
            $day->subDay();
        }

        return $day;
    }

    private function publishToRedis(string $symbol, string $timeframe, array $candle): void
    {
        try {
            Redis::publish("candles:{$symbol}:{$timeframe}", json_encode([
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'candle' => $candle,
                'published_at' => now()->utc()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::debug("NSELiveFeed: Redis publish skipped — {$e->getMessage()}");
        }
    }

    private function broadcastCandle(string $symbol, string $timeframe, array $candle): void
    {
        try {
            broadcast(new CandleUpdated($symbol, $timeframe, $candle));
        } catch (\Throwable $e) {
            Log::debug("NSELiveFeed: broadcast skipped — {$e->getMessage()}");
        }
    }
}
