<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\Symbol;
use App\Services\DataSources\BinanceDataSource;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BootstrapCandlesCommand extends Command
{
    protected $signature = 'candles:bootstrap
        {--symbol=BTCUSDT : Symbol ticker to bootstrap}
        {--exchange=binance : Exchange name}';

    protected $description = 'Fetch historical candle data synchronously for quick setup';

    public function handle(): int
    {
        $ticker = $this->option('symbol');
        $exchange = $this->option('exchange');

        // Ensure symbol exists
        $symbol = Symbol::firstOrCreate(
            ['exchange' => $exchange, 'ticker' => $ticker],
            [
                'name' => $ticker,
                'type' => 'crypto',
                'session' => '24x7',
                'timezone' => 'UTC',
            ]
        );

        $this->info("Bootstrapping {$symbol->ticker} on {$symbol->exchange}...");

        $dataSource = new BinanceDataSource();

        $fetches = [
            ['timeframe' => '1M', 'from' => Carbon::now()->subDay(), 'label' => 'last 24h'],
            ['timeframe' => '5M', 'from' => Carbon::now()->subDays(3), 'label' => 'last 3 days'],
            ['timeframe' => '15M', 'from' => Carbon::now()->subDays(7), 'label' => 'last 7 days'],
            ['timeframe' => '1H', 'from' => Carbon::now()->subDays(30), 'label' => 'last 30 days'],
            ['timeframe' => '4H', 'from' => Carbon::now()->subDays(60), 'label' => 'last 60 days'],
            ['timeframe' => '1D', 'from' => Carbon::now()->subDays(365), 'label' => 'last 365 days'],
        ];

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
}
