<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\Symbol;
use App\Services\CandleAggregationService;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BootstrapHistoricalCommand extends Command
{
    protected $signature = 'data:bootstrap
        {--symbol= : Specific ticker (e.g. RELIANCE). Omit to bootstrap all active symbols.}
        {--exchange=NSE : Exchange filter (NSE, BSE, or all)}
        {--months=3 : How many months of 1M data to fetch (max 12)}
        {--skip-existing : Skip symbols that already have candle data}
        {--dry-run : Show plan without fetching}';

    protected $description = 'Bootstrap historical 1-minute candles from Zerodha for all active NSE/BSE symbols and derive higher timeframes';

    private int $totalFetched = 0;
    private int $totalAggregated = 0;
    private int $symbolsDone = 0;
    private int $symbolsFailed = 0;

    public function handle(): int
    {
        $months       = min((int) $this->option('months'), 12);
        $exchange     = $this->option('exchange');
        $skipExisting = $this->option('skip-existing');
        $dryRun       = $this->option('dry-run');

        // ── Resolve symbols ──────────────────────────────────────────────
        if ($ticker = $this->option('symbol')) {
            $symbols = Symbol::where('ticker', $ticker)->active()->get();
        } elseif ($exchange === 'all') {
            $symbols = Symbol::active()->get();
        } else {
            $symbols = Symbol::active()->where('exchange', strtoupper($exchange))->get();
        }

        if ($symbols->isEmpty()) {
            $this->error('No active symbols found. Run php artisan symbols:seed-nse first.');

            return self::FAILURE;
        }

        // ── Plan ─────────────────────────────────────────────────────────
        $from = Carbon::now()->subMonths($months)->startOfDay();
        $to   = Carbon::now();

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║         WaveTrader v3 — Historical Data Bootstrap       ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info('');
        $this->info("  Symbols   : {$symbols->count()}");
        $this->info("  Exchange  : {$exchange}");
        $this->info("  Period    : {$from->format('Y-m-d')} → {$to->format('Y-m-d')} ({$months} months)");
        $this->info("  Base TF   : 1M (minute candles)");
        $this->info("  Derived   : 5M, 15M, 1H, 4H, 1D (via aggregation)");
        $this->info("  Source    : Zerodha KiteConnect v3");
        $this->info("  Rate limit: 3 req/sec (340ms delay between chunks)");
        $this->info('');

        if ($dryRun) {
            $this->table(
                ['#', 'Exchange', 'Ticker', 'Name', 'Type', 'Existing 1M Candles'],
                $symbols->map(fn ($s, $i) => [
                    $i + 1, $s->exchange, $s->ticker, $s->name, $s->type,
                    Candle::where('symbol_id', $s->id)->where('timeframe', '1M')->count(),
                ])
            );
            $this->info('Dry run — no data fetched.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Proceed with fetching {$months} months of 1M data for {$symbols->count()} symbols?")) {
            return self::SUCCESS;
        }

        // ── Bootstrap each symbol ────────────────────────────────────────
        $zerodha     = new ZerodhaDataSource();
        $aggregation = new CandleAggregationService();
        $startTime   = microtime(true);

        foreach ($symbols as $index => $symbol) {
            $num = $index + 1;
            $total = $symbols->count();

            // Skip if already has data
            if ($skipExisting) {
                $existing = Candle::where('symbol_id', $symbol->id)
                    ->where('timeframe', '1M')
                    ->count();
                if ($existing > 1000) {
                    $this->line("  [{$num}/{$total}] ⏭  {$symbol->ticker} — already has {$existing} candles, skipping");
                    $this->symbolsDone++;

                    continue;
                }
            }

            $this->info("  [{$num}/{$total}] 📡 {$symbol->ticker} ({$symbol->name})");
            $this->output->write("             Fetching 1M candles... ");

            try {
                $candles = $zerodha->fetchCandles($symbol->ticker, '1M', $from->copy(), $to->copy());

                if ($candles->isEmpty()) {
                    $this->line("<fg=yellow>0 candles (no data from Zerodha)</>");
                    $this->symbolsFailed++;

                    continue;
                }

                // Upsert into candles table
                $upserted = 0;
                foreach ($candles->chunk(500) as $chunk) {
                    foreach ($chunk as $c) {
                        Candle::upsert([
                            [
                                'symbol_id' => $symbol->id,
                                'timeframe' => '1M',
                                'timestamp' => $c['timestamp'],
                                'open'      => $c['open'],
                                'high'      => $c['high'],
                                'low'       => $c['low'],
                                'close'     => $c['close'],
                                'volume'    => $c['volume'],
                            ],
                        ], ['symbol_id', 'timeframe', 'timestamp'], ['open', 'high', 'low', 'close', 'volume']);
                        $upserted++;
                    }
                }

                $this->line("<fg=green>{$upserted} candles</>");
                $this->totalFetched += $upserted;

                // Derive higher timeframes
                $this->output->write("             Aggregating 5M/15M/1H/4H/1D... ");

                $derived = 0;
                foreach (['5M', '15M', '1H', '4H', '1D'] as $tf) {
                    $agg = $aggregation->aggregate($symbol->id, $tf, $from, $to);
                    $derived += $agg->count();
                }

                $this->line("<fg=green>{$derived} derived candles</>");
                $this->totalAggregated += $derived;
                $this->symbolsDone++;

            } catch (\Throwable $e) {
                $this->line("<fg=red>FAILED: {$e->getMessage()}</>");
                Log::error("Bootstrap failed for {$symbol->ticker}: {$e->getMessage()}");
                $this->symbolsFailed++;
            }

            // Progress summary every 5 symbols
            if ($num % 5 === 0) {
                $elapsed = round(microtime(true) - $startTime, 1);
                $rate    = $num > 0 ? round($elapsed / $num, 1) : 0;
                $eta     = round($rate * ($total - $num) / 60, 1);
                $this->info("             ── Progress: {$num}/{$total} | Elapsed: {$elapsed}s | ETA: {$eta}min ──");
            }
        }

        // ── Summary ──────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $startTime, 1);

        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║                  Bootstrap Complete                      ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info("  Symbols processed : {$this->symbolsDone}");
        $this->info("  Symbols failed    : {$this->symbolsFailed}");
        $this->info("  1M candles fetched: " . number_format($this->totalFetched));
        $this->info("  Derived candles   : " . number_format($this->totalAggregated));
        $this->info("  Time elapsed      : {$elapsed}s");
        $this->newLine();

        if ($this->symbolsFailed > 0) {
            $this->warn("⚠  {$this->symbolsFailed} symbols failed. Check logs for details.");
        }

        return self::SUCCESS;
    }
}
