<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\Symbol;
use App\Services\DataSources\BinanceDataSource;
use App\Services\DataSources\DataSourceInterface;
use App\Services\DataSources\OANDADataSource;
use App\Services\DataSources\YahooDataSource;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BootstrapCandlesCommand extends Command
{
    protected $signature = 'candles:bootstrap
        {--symbol=BTCUSDT : Symbol ticker to bootstrap}
        {--exchange=binance : Exchange name}
        {--type=crypto : Asset type (crypto, forex, equity, index, commodity)}
        {--session= : Trading session hours}
        {--timezone=UTC : Symbol timezone}';

    protected $description = 'Fetch historical candle data synchronously for quick setup';

    private const EXCHANGE_DEFAULTS = [
        'binance' => ['type' => 'crypto', 'session' => '24x7', 'timezone' => 'UTC'],
        'oanda' => ['type' => 'forex', 'session' => '2200-2200', 'timezone' => 'UTC'],
        'zerodha' => ['type' => 'equity', 'session' => '0915-1530', 'timezone' => 'Asia/Kolkata'],
        'yahoo' => ['type' => 'index', 'session' => '0915-1530', 'timezone' => 'Asia/Kolkata'],
    ];

    public function handle(): int
    {
        $ticker = $this->option('symbol');
        $exchange = $this->option('exchange');
        $defaults = self::EXCHANGE_DEFAULTS[$exchange] ?? ['type' => 'equity', 'session' => '24x7', 'timezone' => 'UTC'];

        // Ensure symbol exists
        $symbol = Symbol::firstOrCreate(
            ['exchange' => $exchange, 'ticker' => $ticker],
            [
                'name' => $ticker,
                'type' => $this->option('type') ?? $defaults['type'],
                'session' => $this->option('session') ?? $defaults['session'],
                'timezone' => $this->option('timezone') ?? $defaults['timezone'],
            ]
        );

        $this->info("Bootstrapping {$symbol->ticker} on {$symbol->exchange}...");

        $dataSource = $this->resolveDataSource($exchange);

        $fetches = $this->getFetchPlan($exchange);

        $totalCandles = 0;

        foreach ($fetches as $fetch) {
            $this->info("  Fetching {$fetch['timeframe']} ({$fetch['label']})...");

            $candles = $dataSource->fetchCandles(
                $symbol->ticker,
                $fetch['timeframe'],
                $fetch['from'],
                Carbon::now(),
            );

            if ($candles->isEmpty()) {
                $this->warn("    No candles returned for {$fetch['timeframe']}");
                continue;
            }

            $mapped = $candles->map(fn (array $c) => [...$c, 'symbol_id' => $symbol->id])->toArray();

            // Upsert in chunks to avoid memory issues
            foreach (array_chunk($mapped, 500) as $chunk) {
                Candle::upsertCandles($chunk);
            }

            $count = $candles->count();
            $totalCandles += $count;
            $this->info("    Stored {$count} candles");
        }

        $this->newLine();
        $this->info("Bootstrap complete! Total candles: {$totalCandles}");
        $this->info("DB total: " . Candle::where('symbol_id', $symbol->id)->count());

        return self::SUCCESS;
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

    private function getFetchPlan(string $exchange): array
    {
        // Yahoo has limited intraday history (max 7d for 1m, 60d for others)
        if ($exchange === 'yahoo') {
            return [
                ['timeframe' => '15M', 'from' => Carbon::now()->subDays(55), 'label' => 'last 55 days'],
                ['timeframe' => '1H', 'from' => Carbon::now()->subDays(55), 'label' => 'last 55 days'],
                ['timeframe' => '1D', 'from' => Carbon::now()->subDays(365), 'label' => 'last 365 days'],
            ];
        }

        // OANDA / Zerodha — no 1M bulk history, start from 15M
        if (in_array($exchange, ['oanda', 'zerodha'])) {
            return [
                ['timeframe' => '15M', 'from' => Carbon::now()->subDays(30), 'label' => 'last 30 days'],
                ['timeframe' => '1H', 'from' => Carbon::now()->subDays(60), 'label' => 'last 60 days'],
                ['timeframe' => '4H', 'from' => Carbon::now()->subDays(120), 'label' => 'last 120 days'],
                ['timeframe' => '1D', 'from' => Carbon::now()->subDays(365), 'label' => 'last 365 days'],
            ];
        }

        // Binance (default) — full range
        return [
            ['timeframe' => '1M', 'from' => Carbon::now()->subDay(), 'label' => 'last 24h'],
            ['timeframe' => '5M', 'from' => Carbon::now()->subDays(3), 'label' => 'last 3 days'],
            ['timeframe' => '15M', 'from' => Carbon::now()->subDays(7), 'label' => 'last 7 days'],
            ['timeframe' => '1H', 'from' => Carbon::now()->subDays(30), 'label' => 'last 30 days'],
            ['timeframe' => '4H', 'from' => Carbon::now()->subDays(60), 'label' => 'last 60 days'],
            ['timeframe' => '1D', 'from' => Carbon::now()->subDays(365), 'label' => 'last 365 days'],
        ];
    }
}
