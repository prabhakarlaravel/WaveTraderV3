<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\CandleUpdated;
use App\Models\Candle;
use App\Models\Symbol;
use App\Services\CandleAggregationService;
use App\Services\DataSources\BinanceDataSource;
use App\Services\DataSources\DataSourceInterface;
use App\Services\DataSources\OANDADataSource;
use App\Services\DataSources\YahooDataSource;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchCandlesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly int $symbolId,
        public readonly string $timeframe = '1M',
    ) {}

    public function handle(): void
    {
        $symbol = Symbol::findOrFail($this->symbolId);

        $dataSource = $this->resolveDataSource($symbol->exchange);

        // Determine fetch window: from last stored candle to now
        // Use the last candle's timestamp (NOT +1s) so the current forming
        // candle gets re-fetched and its OHLCV updated via upsert every 30s
        $lastCandle = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $this->timeframe)
            ->orderByDesc('timestamp')
            ->first();

        $from = $lastCandle ? $lastCandle->timestamp : Carbon::now()->subMonths(3);
        $to = Carbon::now();

        $candles = $dataSource->fetchCandles($symbol->ticker, $this->timeframe, $from, $to);

        if ($candles->isEmpty()) {
            Log::info("FetchCandlesJob: no new candles for {$symbol->ticker} [{$this->timeframe}]");

            return;
        }

        // Attach symbol_id to each candle
        $mapped = $candles->map(fn (array $c) => [...$c, 'symbol_id' => $symbol->id])->toArray();

        // Upsert candles (rule #2: INSERT ... ON CONFLICT DO UPDATE)
        Candle::upsertCandles($mapped);

        Log::info("FetchCandlesJob: upserted {$candles->count()} candles for {$symbol->ticker} [{$this->timeframe}]");

        // Derive higher timeframes from 1M base (rule #10)
        $aggregatedCandles = [];
        if ($this->timeframe === '1M') {
            $aggregator = new CandleAggregationService();
            $aggregatedCandles = $aggregator->aggregateFromOneMinute($symbol->id);
            $tfCount = count($aggregatedCandles);
            Log::info("FetchCandlesJob: aggregated 1M into {$tfCount} higher timeframes for {$symbol->ticker}");
        }

        // Broadcast candle updates for ALL timeframes
        try {
            // Broadcast 1M update
            $lastCandleData = $candles->last();
            broadcast(new CandleUpdated($symbol->ticker, $this->timeframe, $lastCandleData));

            // Broadcast each aggregated higher timeframe update
            foreach ($aggregatedCandles as $tf => $candleData) {
                if ($candleData) {
                    broadcast(new CandleUpdated($symbol->ticker, $tf, $candleData));
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Broadcasting skipped (Reverb not running?): {$e->getMessage()}");
        }

        // Dispatch engine runs after candle fetch (rule #8: engines queue)
        RunEnginesJob::dispatch($this->symbolId, $this->timeframe)->onQueue('engines');
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
