<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Engines\ElliottWaveEngine;
use App\Engines\EngineResult;
use App\Engines\FVGEngine;
use App\Engines\MarketStructureEngine;
use App\Engines\OrderBlockEngine;
use App\Engines\PriceActionEngine;
use App\Engines\SMCEngine;
use App\Engines\VWAPEngine;
use App\Events\FVGUpdated;
use App\Events\OrderBlockUpdated;
use App\Events\SignalGenerated;
use App\Models\Candle;
use App\Models\FVG;
use App\Models\OrderBlock;
use App\Models\Signal;
use App\Models\Symbol;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunEnginesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $symbolId,
        public readonly string $timeframe = '1M',
    ) {
        $this->onQueue('engines');
    }

    public function handle(): void
    {
        $symbol = Symbol::findOrFail($this->symbolId);

        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $this->timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (empty($candles)) {
            return;
        }

        $engines = [
            new MarketStructureEngine(),
            new OrderBlockEngine(),
            new FVGEngine(),
            new PriceActionEngine(),
            new ElliottWaveEngine(),
            new SMCEngine(),
            new VWAPEngine(),
        ];

        $allSignals = [];

        foreach ($engines as $engine) {
            try {
                $result = $engine->run($candles, $symbol->ticker, $this->timeframe);
                $this->persistResult($result, $symbol->id);
                $allSignals = array_merge($allSignals, $result->signals);
            } catch (\Throwable $e) {
                $engineName = isset($result) ? $result->engine : get_class($engine);
                Log::error("Engine {$engineName} failed: {$e->getMessage()}");
            }
        }

        // Broadcast aggregated results (only if Reverb is running)
        try {
            if (! empty($allSignals)) {
                broadcast(new SignalGenerated($symbol->ticker, array_slice($allSignals, -20)));
            }

            $obs = OrderBlock::where('symbol_id', $symbol->id)
                ->where('timeframe', $this->timeframe)
                ->where('status', '!=', 'fully_mitigated')
                ->get()
                ->toArray();
            broadcast(new OrderBlockUpdated($symbol->ticker, $obs));

            $fvgs = FVG::where('symbol_id', $symbol->id)
                ->where('timeframe', $this->timeframe)
                ->where('fill_pct', '<', 100)
                ->get()
                ->toArray();
            broadcast(new FVGUpdated($symbol->ticker, $fvgs));
        } catch (\Throwable $e) {
            Log::warning("Broadcasting skipped (Reverb not running?): {$e->getMessage()}");
        }

        Log::info("Engines completed for {$symbol->ticker} [{$this->timeframe}]: " . count($allSignals) . " signals");
    }

    private function persistResult(EngineResult $result, int $symbolId): void
    {
        // Persist signals
        if (! empty($result->signals)) {
            $signalRows = array_map(fn (array $s) => [
                ...$s,
                'symbol_id' => $symbolId,
                'timeframe' => $result->timeframe,
                'created_at' => now(),
                'updated_at' => now(),
            ], $result->signals);

            // Only insert signals from the last candle to avoid duplicates
            Signal::where('symbol_id', $symbolId)
                ->where('timeframe', $result->timeframe)
                ->where('engine', $result->engine)
                ->delete();

            foreach (array_chunk($signalRows, 500) as $chunk) {
                Signal::insert($chunk);
            }
        }

        // Persist order blocks
        $overlayObs = $result->overlays['orderBlocks'] ?? [];
        if (! empty($overlayObs)) {
            // Clear old OBs for this engine run and re-insert
            OrderBlock::where('symbol_id', $symbolId)
                ->where('timeframe', $result->timeframe)
                ->delete();

            $obRows = array_map(fn (array $ob) => [
                ...$ob,
                'symbol_id' => $symbolId,
                'timeframe' => $result->timeframe,
                'created_at' => now(),
                'updated_at' => now(),
            ], $overlayObs);

            foreach (array_chunk($obRows, 500) as $chunk) {
                OrderBlock::insert($chunk);
            }
        }

        // Persist FVGs
        $overlayFvgs = $result->overlays['fvgs'] ?? [];
        if (! empty($overlayFvgs)) {
            FVG::where('symbol_id', $symbolId)
                ->where('timeframe', $result->timeframe)
                ->delete();

            $fvgRows = array_map(fn (array $f) => [
                'symbol_id' => $symbolId,
                'timeframe' => $result->timeframe,
                'type' => $f['type'],
                'high' => $f['high'],
                'low' => $f['low'],
                'formed_at' => $f['formed_at'],
                'fill_pct' => $f['fill_pct'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $overlayFvgs);

            foreach (array_chunk($fvgRows, 500) as $chunk) {
                FVG::insert($chunk);
            }
        }
    }
}
