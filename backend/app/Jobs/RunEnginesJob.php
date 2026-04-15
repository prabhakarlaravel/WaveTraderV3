<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Engines\ConfluenceEngine;
use App\Engines\ElliottWaveEngine;
use App\Engines\EngineResult;
use App\Engines\FVGEngine;
use App\Engines\MarketStructureEngine;
use App\Engines\OrderBlockEngine;
use App\Engines\PriceActionEngine;
use App\Engines\SMCEngine;
use App\Engines\VWAPEngine;
use App\Events\OverlaysUpdated;
use App\Events\SignalGenerated;
use App\Models\Candle;
use App\Models\FVG;
use App\Models\OrderBlock;
use App\Models\Signal;
use App\Models\Symbol;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RunEnginesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $uniqueFor = 25;

    public function __construct(
        public readonly int $symbolId,
        public readonly string $timeframe = '1M',
    ) {
        $this->onQueue('engines');
    }

    public function uniqueId(): string
    {
        return "engines:{$this->symbolId}:{$this->timeframe}";
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

        // Run engines in two phases:
        // Phase 1: Level-producing engines (OB, FVG, SMC, VWAP, EW, MS)
        // Phase 2: PriceAction with injected confluence levels from Phase 1
        $results = [];
        $allSignals = [];

        $phase1Engines = [
            'market_structure' => new MarketStructureEngine(),
            'order_block' => new OrderBlockEngine(),
            'fvg' => new FVGEngine(),
            'elliott_wave' => new ElliottWaveEngine(),
            'smc' => new SMCEngine(),
            'vwap' => new VWAPEngine(),
        ];

        foreach ($phase1Engines as $key => $engine) {
            try {
                $result = $engine->run($candles, $symbol->ticker, $this->timeframe);
                $results[$key] = $result;
                $this->persistResult($result, $symbol->id);
                $allSignals = array_merge($allSignals, $result->signals);
            } catch (\Throwable $e) {
                Log::error("Engine {$key} failed: {$e->getMessage()}");
                $results[$key] = null;
            }
        }

        // Phase 2: PriceAction with confluence levels from Phase 1
        $confluenceLevels = $this->extractConfluenceLevels($results, $candles);
        $paEngine = new PriceActionEngine();
        $paEngine->setConfluenceLevels($confluenceLevels);
        try {
            $result = $paEngine->run($candles, $symbol->ticker, $this->timeframe);
            $results['price_action'] = $result;
            $this->persistResult($result, $symbol->id);
            $allSignals = array_merge($allSignals, $result->signals);
        } catch (\Throwable $e) {
            Log::error("Engine price_action failed: {$e->getMessage()}");
            $results['price_action'] = null;
        }

        // Build and cache full overlay payload
        $payload = $this->buildOverlayPayload($results, $symbol, $allSignals, $candles);
        $this->cacheOverlayPayload($symbol->id, $payload);

        // Broadcast full overlays via Reverb
        try {
            broadcast(new OverlaysUpdated(
                $symbol->ticker,
                $this->timeframe,
                $payload,
                $payload['computed_at'],
            ));

            if (! empty($allSignals)) {
                broadcast(new SignalGenerated($symbol->ticker, array_slice($allSignals, -20)));
            }
        } catch (\Throwable $e) {
            Log::warning("Broadcasting skipped (Reverb not running?): {$e->getMessage()}");
        }

        Log::info("Engines completed for {$symbol->ticker} [{$this->timeframe}]: " . count($allSignals) . " signals (cached)");
    }

    /**
     * Assemble the full overlay payload matching what the frontend expects.
     */
    private function buildOverlayPayload(array $results, Symbol $symbol, array $allSignals, array $candles): array
    {
        $ms = $results['market_structure'];
        $ob = $results['order_block'];
        $fvg = $results['fvg'];
        $pa = $results['price_action'];
        $ew = $results['elliott_wave'];
        $smc = $results['smc'];
        $vwap = $results['vwap'];

        // Get persisted signals from DB (freshly written above)
        $signals = Signal::where('symbol_id', $symbol->id)
            ->where('timeframe', $this->timeframe)
            ->orderByDesc('candle_timestamp')
            ->limit(200)
            ->get()
            ->toArray();

        // Compute HTF bias from cached overlays across 1D, 4H, 1H
        $htfBias = $this->computeHtfBias($symbol->id);

        // Build per-TF trend map so ConfluenceEngine can check alignment
        // across EVERY timeframe above the active one — not just the
        // aggregate bias which can hide 4H+1H agreement under a noisy 1D.
        $htfTrends = $this->computeHtfTrends($symbol->id);

        // Compute confluence score with HTF bias (Layer 2 conflict gate)
        $lastClose = ! empty($candles) ? (float) end($candles)['close'] : 0;
        $confluence = null;
        try {
            if ($ew && $ms && $ob && $fvg && $smc && $vwap && $pa) {
                $confluence = (new ConfluenceEngine())->score(
                    $ew, $ms, $ob, $fvg, $smc, $vwap, $pa,
                    $lastClose, $htfBias, $this->timeframe, $htfTrends,
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Confluence scoring failed: {$e->getMessage()}");
        }

        return [
            'signals' => $signals,
            'orderBlocks' => $ob?->overlays['orderBlocks'] ?? [],
            'fvgs' => $fvg?->overlays['fvgs'] ?? [],
            'swings' => $ms?->overlays['swings'] ?? [],
            'waveLabels' => $ew?->overlays['waveLabels'] ?? [],
            'subLegs' => $ew?->overlays['subLegs'] ?? [],
            'formingWave' => $ew?->overlays['formingWave'] ?? null,
            'bos' => $ms?->overlays['bos'] ?? [],
            'vwap' => $vwap?->overlays['vwap'] ?? [],
            'patterns' => $pa?->overlays['patterns'] ?? [],
            'fibTargets' => $ew?->overlays['fibTargets'] ?? [],
            'nextTargets' => $ew?->overlays['nextTargets'] ?? [],
            'timeEstimate' => $ew?->overlays['timeEstimate'] ?? [],
            'liquidityPools' => $smc?->overlays['liquidityPools'] ?? [],
            'oteZones' => $smc?->overlays['oteZones'] ?? [],
            'premiumDiscount' => $smc?->overlays['premiumDiscount'] ?? [],
            'inducements' => $smc?->overlays['inducements'] ?? [],
            'confluence' => $confluence,
            'metadata' => [
                'trend' => $ms?->metadata['trend'] ?? 'neutral',
                'elliott_wave' => $ew?->metadata ?? [],
                'smc' => $smc?->metadata ?? [],
            ],
            'computed_at' => now()->toISOString(),
        ];
    }

    /**
     * Cache full overlay payload in Redis with 120s TTL.
     */
    private function cacheOverlayPayload(int $symbolId, array $payload): void
    {
        $key = "overlays:{$symbolId}:{$this->timeframe}";
        // 3600s TTL — long safety net for development (no persistent queue worker).
        // In production with Horizon, cache is refreshed every 30s anyway.
        Redis::setex($key, 3600, json_encode($payload));
    }

    /**
     * Read cached overlay payload from Redis.
     */
    public static function getCachedOverlays(int $symbolId, string $timeframe): ?array
    {
        $key = "overlays:{$symbolId}:{$timeframe}";
        $cached = Redis::get($key);

        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Compute weighted HTF bias from 1D, 4H, 1H cached trends.
     * 1D carries 3x weight, 4H carries 2x, 1H carries 1x.
     */
    private function computeHtfBias(int $symbolId): string
    {
        $htfWeights = ['1D' => 3, '4H' => 2, '1H' => 1];
        $bullScore = 0;
        $bearScore = 0;

        foreach ($htfWeights as $tf => $weight) {
            $cached = self::getCachedOverlays($symbolId, $tf);
            $trend = $cached['metadata']['trend'] ?? 'neutral';

            if ($trend === 'bullish') {
                $bullScore += $weight;
            } elseif ($trend === 'bearish') {
                $bearScore += $weight;
            }
        }

        // Need at least 2 weighted points to declare a bias (avoids 1H-only bias)
        if ($bullScore >= 2 && $bullScore > $bearScore) {
            return 'BULL';
        }
        if ($bearScore >= 2 && $bearScore > $bullScore) {
            return 'BEAR';
        }

        return 'NEUTRAL';
    }

    /**
     * Build a per-timeframe trend map for every cached TF.
     * Returns ['1M'=>'BULL'|'BEAR'|'NEUTRAL', ...]. Missing TFs map to NEUTRAL.
     */
    private function computeHtfTrends(int $symbolId): array
    {
        $tfs = ['1M', '5M', '15M', '1H', '4H', '1D'];
        $map = [];
        foreach ($tfs as $tf) {
            $cached = self::getCachedOverlays($symbolId, $tf);
            $trend = $cached['metadata']['trend'] ?? 'neutral';
            $map[$tf] = match ($trend) {
                'bullish' => 'BULL',
                'bearish' => 'BEAR',
                default => 'NEUTRAL',
            };
        }

        return $map;
    }

    /**
     * Extract key price levels from Phase 1 engine results for PriceAction context.
     */
    private function extractConfluenceLevels(array $results, array $candles): array
    {
        $levels = [];

        // Fresh order block midpoints
        if ($results['order_block'] ?? null) {
            foreach ($results['order_block']->overlays['orderBlocks'] ?? [] as $ob) {
                if (($ob['status'] ?? '') === 'fresh') {
                    $levels[] = ['price' => ($ob['high'] + $ob['low']) / 2, 'type' => 'ob'];
                }
            }
        }

        // Unfilled FVG midpoints
        if ($results['fvg'] ?? null) {
            foreach ($results['fvg']->overlays['fvgs'] ?? [] as $fvg) {
                if (($fvg['fill_pct'] ?? 100) < 50) {
                    $levels[] = ['price' => ($fvg['high'] + $fvg['low']) / 2, 'type' => 'fvg'];
                }
            }
        }

        // OTE zone midpoints
        if ($results['smc'] ?? null) {
            foreach ($results['smc']->overlays['oteZones'] ?? [] as $ote) {
                $levels[] = ['price' => ($ote['high'] + $ote['low']) / 2, 'type' => 'ote'];
            }
        }

        // VWAP level
        if ($results['vwap'] ?? null) {
            $vwapData = $results['vwap']->overlays['vwap'] ?? [];
            if (! empty($vwapData)) {
                $lastVwap = end($vwapData);
                $levels[] = ['price' => $lastVwap['vwap'], 'type' => 'vwap'];
            }
        }

        return $levels;
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
