<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Engines\ConfluenceEngine;
use App\Engines\ElliottWaveEngine;
use App\Engines\FVGEngine;
use App\Engines\MarketStructureEngine;
use App\Engines\OrderBlockEngine;
use App\Engines\PriceActionEngine;
use App\Engines\SMCEngine;
use App\Engines\VWAPEngine;
use App\Http\Controllers\Controller;
use App\Events\CandleUpdated;
use App\Models\Candle;
use App\Models\FVG;
use App\Models\OrderBlock;
use App\Models\Signal;
use App\Models\Symbol;
use App\Services\CandleAggregationService;
use App\Services\DataSources\BinanceDataSource;
use App\Services\DataSources\DataSourceInterface;
use App\Services\DataSources\OANDADataSource;
use App\Services\DataSources\YahooDataSource;
use App\Services\DataSources\ZerodhaDataSource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ChartController extends Controller
{
    private const TIMEFRAMES = ['1M', '5M', '15M', '1H', '4H', '1D'];

    /**
     * Fetch latest candles from exchange → upsert DB → publish to Redis →
     * broadcast via Reverb → return fresh candles.
     *
     * Full pipeline: Exchange API → DB → Redis Pub/Sub → Reverb WebSocket → Echo → Vue chart
     * Called every 30s by frontend polling.
     */
    public function fetchLatest(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|string|in:1M,5M,15M,1H,4H,1D',
        ]);

        $symbol = Symbol::findOrFail($request->symbol_id);
        $timeframe = $request->timeframe;
        $aggregatedCandles = [];

        try {
            $dataSource = $this->resolveDataSource($symbol->exchange);

            // Step 1: Fetch last 15 minutes of 1M candles from exchange
            // (15 min window ensures we catch up after any gaps/pauses)
            $from = Carbon::now()->utc()->subMinutes(15);
            $to = Carbon::now()->utc();
            $fetched = $dataSource->fetchCandles($symbol->ticker, '1M', $from, $to);

            if ($fetched->isNotEmpty()) {
                // Step 2: Upsert to DB (rule #2: INSERT ... ON CONFLICT DO UPDATE)
                $mapped = $fetched->map(fn (array $c) => [...$c, 'symbol_id' => $symbol->id])->toArray();
                Candle::upsertCandles($mapped);

                // Step 3: Publish 1M candle to Redis channel (rule #4)
                $latestCandle = $fetched->last();
                $this->publishToRedis($symbol->ticker, '1M', $latestCandle);

                // Step 4: Broadcast 1M CandleUpdated via Reverb WebSocket
                $this->broadcastCandle($symbol->ticker, '1M', $latestCandle);

                // Step 5: Aggregate higher TFs from 1M base (rule #10)
                $aggregator = new CandleAggregationService();
                $aggregatedCandles = $aggregator->aggregateFromOneMinute($symbol->id);

                // Step 6: Publish + broadcast each aggregated higher TF
                foreach ($aggregatedCandles as $tf => $candleData) {
                    if ($candleData) {
                        $this->publishToRedis($symbol->ticker, $tf, $candleData);
                        $this->broadcastCandle($symbol->ticker, $tf, $candleData);
                    }
                }

                Log::debug("fetchLatest: {$symbol->ticker} — {$fetched->count()} 1M candles, " .
                    count($aggregatedCandles) . " TFs aggregated, Redis+Reverb published");
            }
        } catch (\Throwable $e) {
            Log::warning("fetchLatest failed for {$symbol->ticker}: {$e->getMessage()}");
        }

        // Return last N candles for the requested timeframe
        $limit = $request->query('limit', 5);
        $candles = Candle::forSymbol($symbol->id, $timeframe)
            ->orderByDesc('timestamp')
            ->limit((int) $limit)
            ->get()
            ->sortBy('timestamp')
            ->values();

        return response()->json($candles);
    }

    /**
     * Publish candle to Redis Pub/Sub channel for SSE streaming (rule #4).
     */
    private function publishToRedis(string $symbol, string $timeframe, array $candle): void
    {
        try {
            $channel = "candles:{$symbol}:{$timeframe}";
            Redis::publish($channel, json_encode([
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'candle' => $candle,
                'published_at' => now()->utc()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::debug("Redis publish skipped: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast CandleUpdated event via Laravel Reverb WebSocket.
     */
    private function broadcastCandle(string $symbol, string $timeframe, array $candle): void
    {
        try {
            broadcast(new CandleUpdated($symbol, $timeframe, $candle));
        } catch (\Throwable $e) {
            Log::debug("Reverb broadcast skipped: {$e->getMessage()}");
        }
    }

    /**
     * Resolve data source adapter based on exchange name.
     */
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

    public function candles(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|string|in:1M,5M,15M,1H,4H,1D',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = Candle::forSymbol($request->symbol_id, $request->timeframe)
            ->orderBy('timestamp');

        if ($request->from) {
            $query->where('timestamp', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('timestamp', '<=', $request->to);
        }

        return response()->json($query->get());
    }

    public function overlays(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|string|in:1M,5M,15M,1H,4H,1D',
        ]);

        $symbolId = $request->symbol_id;
        $timeframe = $request->timeframe;
        $symbol = Symbol::findOrFail($symbolId);

        // Get candles for live computation
        $candles = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        // Run engines live to get fresh overlay data
        $msResult = (new MarketStructureEngine(5))->run($candles, $symbol->ticker, $timeframe);
        $obResult = (new OrderBlockEngine())->run($candles, $symbol->ticker, $timeframe);
        $fvgResult = (new FVGEngine())->run($candles, $symbol->ticker, $timeframe);
        $vwapResult = (new VWAPEngine())->run($candles, $symbol->ticker, $timeframe);
        $paResult = (new PriceActionEngine())->run($candles, $symbol->ticker, $timeframe);
        $ewResult = (new ElliottWaveEngine())->run($candles, $symbol->ticker, $timeframe);
        $smcResult = (new SMCEngine())->run($candles, $symbol->ticker, $timeframe);

        // Wave labels from Elliott Wave Engine (with degree, phase, fib targets)
        $waveLabels = $ewResult->overlays['waveLabels'] ?? [];
        $swings = $msResult->overlays['swings'] ?? [];

        // Get DB-persisted signals
        $signals = Signal::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->orderByDesc('candle_timestamp')
            ->limit(200)
            ->get();

        return response()->json([
            'signals' => $signals,
            'orderBlocks' => $obResult->overlays['orderBlocks'] ?? [],
            'fvgs' => $fvgResult->overlays['fvgs'] ?? [],
            'swings' => $swings,
            'waveLabels' => $waveLabels,
            'bos' => $msResult->overlays['bos'] ?? [],
            'vwap' => $vwapResult->overlays['vwap'] ?? [],
            'patterns' => $paResult->overlays['patterns'] ?? [],
            'fibTargets' => $ewResult->overlays['fibTargets'] ?? [],
            'nextTargets' => $ewResult->overlays['nextTargets'] ?? [],
            'liquidityPools' => $smcResult->overlays['liquidityPools'] ?? [],
            'oteZones' => $smcResult->overlays['oteZones'] ?? [],
            'premiumDiscount' => $smcResult->overlays['premiumDiscount'] ?? [],
            'inducements' => $smcResult->overlays['inducements'] ?? [],
            'confluence' => (new ConfluenceEngine())->score(
                $ewResult, $msResult, $obResult, $fvgResult, $smcResult, $vwapResult, $paResult,
                ! empty($candles) ? (float) end($candles)['close'] : 0,
            ),
            'metadata' => [
                'trend' => $msResult->metadata['trend'] ?? 'neutral',
                'elliott_wave' => $ewResult->metadata ?? [],
                'smc' => $smcResult->metadata ?? [],
            ],
        ]);
    }

    /**
     * Derive Elliott Wave labels from swing points.
     * Alternates highs/lows and assigns wave sequence: 1,2,3,4,5,A,B,C
     */
    private function deriveWaveLabels(array $swings): array
    {
        if (count($swings) < 5) {
            return [];
        }

        $waveSequence = ['1', '2', '3', '4', '5', 'A', 'B', 'C'];

        // Filter to alternating high/low sequence, keeping extremes
        $filtered = [$swings[0]];
        for ($i = 1; $i < count($swings); $i++) {
            $last = end($filtered);
            if ($swings[$i]['type'] !== $last['type']) {
                $filtered[] = $swings[$i];
            } else {
                // Same type — keep the more extreme
                if ($swings[$i]['type'] === 'high' && $swings[$i]['price'] > $last['price']) {
                    $filtered[count($filtered) - 1] = $swings[$i];
                }
                if ($swings[$i]['type'] === 'low' && $swings[$i]['price'] < $last['price']) {
                    $filtered[count($filtered) - 1] = $swings[$i];
                }
            }
        }

        $labels = [];
        for ($i = 0; $i < min(count($filtered), count($waveSequence) * 2); $i++) {
            $label = $waveSequence[$i % count($waveSequence)];
            $isCorrection = in_array($label, ['A', 'B', 'C']);
            $labels[] = [
                'type' => $filtered[$i]['type'],
                'price' => $filtered[$i]['price'],
                'timestamp' => $filtered[$i]['timestamp'],
                'label' => $label,
                'isCorrection' => $isCorrection,
            ];
        }

        return $labels;
    }

    /**
     * Multi-timeframe wave analysis — runs Elliott Wave + Market Structure
     * on ALL 6 timeframes and returns wave position, trend, health per TF.
     */
    public function mtfWaves(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
        ]);

        $symbol = Symbol::findOrFail($request->symbol_id);
        $degrees = [
            '1D' => 'Primary', '4H' => 'Intermediate', '1H' => 'Minor',
            '15M' => 'Minute', '5M' => 'Minuette', '1M' => 'Sub-Minuette',
        ];

        $tfData = [];
        $trends = [];

        foreach (self::TIMEFRAMES as $tf) {
            $candles = Candle::where('symbol_id', $symbol->id)
                ->where('timeframe', $tf)
                ->orderBy('timestamp')
                ->get()
                ->toArray();

            if (count($candles) < 50) {
                $tfData[$tf] = [
                    'timeframe' => $tf,
                    'degree' => $degrees[$tf] ?? $tf,
                    'wave' => null,
                    'phase' => null,
                    'trend' => 'neutral',
                    'health' => 0,
                    'waveLabels' => [],
                    'fibTargets' => [],
                ];
                $trends[] = 'neutral';
                continue;
            }

            $ewResult = (new ElliottWaveEngine())->run($candles, $symbol->ticker, $tf);
            $msResult = (new MarketStructureEngine(5))->run($candles, $symbol->ticker, $tf);

            $currentWave = $ewResult->metadata['current_wave'] ?? null;
            $phase = $ewResult->metadata['phase'] ?? null;
            $trend = $msResult->metadata['trend'] ?? 'neutral';
            $health = $ewResult->metadata['health_score'] ?? 0;

            $trends[] = $trend;

            $tfData[$tf] = [
                'timeframe' => $tf,
                'degree' => $degrees[$tf] ?? $tf,
                'wave' => $currentWave,
                'phase' => $phase,
                'trend' => $trend,
                'health' => $health,
                'waveLabels' => $ewResult->overlays['waveLabels'] ?? [],
                'fibTargets' => $ewResult->overlays['fibTargets'] ?? [],
            ];
        }

        // Calculate alignment
        $bullCount = count(array_filter($trends, fn ($t) => $t === 'bullish'));
        $bearCount = count(array_filter($trends, fn ($t) => $t === 'bearish'));
        $totalTfs = count(self::TIMEFRAMES);
        $alignment = max($bullCount, $bearCount);
        $htfBias = $bullCount > $bearCount ? 'BULL' : ($bearCount > $bullCount ? 'BEAR' : 'NEUTRAL');

        // Trend progress: estimate how far through the current wave cycle
        // Based on the highest TF's wave position
        $htfWave = $tfData['1D']['wave'] ?? $tfData['4H']['wave'] ?? null;
        $trendProgress = $this->estimateTrendProgress($htfWave);

        return response()->json([
            'symbol' => $symbol->ticker,
            'timeframes' => $tfData,
            'htfBias' => $htfBias,
            'alignment' => $alignment . '/' . $totalTfs,
            'alignmentPct' => $totalTfs > 0 ? round($alignment / $totalTfs * 100) : 0,
            'trendProgress' => $trendProgress,
        ]);
    }

    private function estimateTrendProgress(?string $wave): array
    {
        $progressMap = [
            '1' => ['pct' => 15, 'label' => 'JUST STARTED', 'stage' => 'start'],
            '2' => ['pct' => 25, 'label' => 'EARLY', 'stage' => 'start'],
            '3' => ['pct' => 50, 'label' => 'MIDDLE', 'stage' => 'middle'],
            '4' => ['pct' => 65, 'label' => 'PAST MIDDLE', 'stage' => 'middle'],
            '5' => ['pct' => 85, 'label' => 'NEAR END', 'stage' => 'end'],
            'A' => ['pct' => 40, 'label' => 'CORRECTION START', 'stage' => 'start'],
            'B' => ['pct' => 60, 'label' => 'CORRECTION MIDDLE', 'stage' => 'middle'],
            'C' => ['pct' => 85, 'label' => 'CORRECTION END', 'stage' => 'end'],
        ];

        return $progressMap[$wave] ?? ['pct' => 50, 'label' => 'UNKNOWN', 'stage' => 'middle'];
    }

    public function symbols(): JsonResponse
    {
        return response()->json(Symbol::active()->get());
    }

    public function storeSymbol(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exchange' => 'required|string|max:20',
            'ticker' => 'required|string|max:40',
            'name' => 'required|string',
            'type' => 'nullable|string|max:20',
            'session' => 'nullable|string|max:40',
            'timezone' => 'nullable|string|max:40',
            'lot_size' => 'nullable|numeric',
            'tick_size' => 'nullable|numeric',
        ]);

        $symbol = Symbol::create($data);

        return response()->json($symbol, 201);
    }

    public function updateSymbol(Request $request, Symbol $symbol): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string',
            'type' => 'nullable|string|max:20',
            'active' => 'nullable|boolean',
            'session' => 'nullable|string|max:40',
            'timezone' => 'nullable|string|max:40',
        ]);

        $symbol->update($data);

        return response()->json($symbol);
    }

    public function deleteSymbol(Symbol $symbol): JsonResponse
    {
        $symbol->delete();

        return response()->json(null, 204);
    }
}
