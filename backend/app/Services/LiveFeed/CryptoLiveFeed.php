<?php

declare(strict_types=1);

namespace App\Services\LiveFeed;

use App\Events\CandleUpdated;
use App\Jobs\RunEnginesJob;
use App\Models\Candle;
use App\Models\Symbol;
use App\Services\CandleAggregationService;
use App\Services\DataSources\BinanceDataSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Crypto live feed — 24/7, no market hours restriction.
 * Uses Binance REST API for candle data.
 */
class CryptoLiveFeed implements LiveFeedInterface
{
    private BinanceDataSource $dataSource;

    public function __construct()
    {
        $this->dataSource = new BinanceDataSource();
    }

    public function isMarketOpen(): bool
    {
        return true; // Crypto markets are always open
    }

    public function fetchLatest(Symbol $symbol, string $timeframe, int $limit = 10): Collection
    {
        try {
            // Fetch last 15 minutes of 1M candles from Binance (UTC — Binance uses UTC natively)
            $from = Carbon::now()->utc()->subMinutes(15);
            $to = Carbon::now()->utc();
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

                Log::debug("CryptoLiveFeed: {$symbol->ticker} — {$fetched->count()} 1M candles, " .
                    count($aggregatedCandles) . " TFs aggregated");

                // Dispatch engine run
                try {
                    RunEnginesJob::dispatch($symbol->id, $timeframe);
                } catch (\Throwable $je) {
                    Log::debug("CryptoLiveFeed: engine dispatch skipped — {$je->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning("CryptoLiveFeed: fetch failed for {$symbol->ticker} — {$e->getMessage()}");
        }

        // Return last N candles for the requested timeframe
        return Candle::forSymbol($symbol->id, $timeframe)
            ->orderByDesc('timestamp')
            ->limit($limit)
            ->get()
            ->sortBy('timestamp')
            ->values();
    }

    public function getMarketStatus(): array
    {
        return [
            'market' => 'crypto',
            'open' => true,
            'session' => '24/7',
            'timezone' => 'UTC',
            'message' => 'Crypto markets are always open',
        ];
    }

    public function getMarketType(): string
    {
        return 'crypto';
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
            Log::debug("CryptoLiveFeed: Redis publish skipped — {$e->getMessage()}");
        }
    }

    private function broadcastCandle(string $symbol, string $timeframe, array $candle): void
    {
        try {
            broadcast(new CandleUpdated($symbol, $timeframe, $candle));
        } catch (\Throwable $e) {
            Log::debug("CryptoLiveFeed: broadcast skipped — {$e->getMessage()}");
        }
    }
}
