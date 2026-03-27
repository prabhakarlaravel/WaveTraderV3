<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\CandleUpdated;
use App\Models\Candle;
use App\Models\Symbol;
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
        $lastCandle = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $this->timeframe)
            ->orderByDesc('timestamp')
            ->first();

        $from = $lastCandle ? $lastCandle->timestamp->addSecond() : Carbon::now()->subMonths(3);
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

        // Broadcast event for Vue frontend
        $lastCandleData = $candles->last();
        broadcast(new CandleUpdated($symbol->ticker, $this->timeframe, $lastCandleData));

        // Dispatch engine runs after candle fetch (rule #8: engines queue)
        RunEnginesJob::dispatch($this->symbolId, $this->timeframe)->onQueue('engines');
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
