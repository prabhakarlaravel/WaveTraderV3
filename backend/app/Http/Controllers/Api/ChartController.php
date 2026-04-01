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
use App\Services\LiveFeed\LiveFeedResolver;
use App\Jobs\RunEnginesJob;
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
     * Uses market-specific LiveFeed services:
     * - CryptoLiveFeed: 24/7 Binance feed (UTC timestamps)
     * - NSELiveFeed: 09:15–15:30 IST weekdays, Zerodha (IST timestamps)
     * - ForexLiveFeed: Sun 22:00 – Fri 22:00 UTC, OANDA/Yahoo
     *
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
        $limit = (int) $request->query('limit', 5);

        // Resolve market-specific live feed service
        $liveFeed = LiveFeedResolver::resolve($symbol);
        $candles = $liveFeed->fetchLatest($symbol, $timeframe, $limit);

        return response()->json($candles);
    }

    /**
     * Get market status for the symbol's exchange (open/closed, session info, next open).
     */
    public function marketStatus(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'nullable|exists:symbols,id',
        ]);

        if ($request->symbol_id) {
            $symbol = Symbol::findOrFail($request->symbol_id);
            $liveFeed = LiveFeedResolver::resolve($symbol);

            return response()->json([
                'symbol' => $symbol->ticker,
                'marketType' => $liveFeed->getMarketType(),
                ...$liveFeed->getMarketStatus(),
            ]);
        }

        // No symbol — return all market statuses
        return response()->json(LiveFeedResolver::getAllMarketStatus());
    }

    /**
     * Resolve data source adapter based on exchange name.
     * Used by non-LiveFeed endpoints (gap fill, historical bootstrap, etc.)
     */
    private function resolveDataSource(string $exchange): DataSourceInterface
    {
        $ex = strtoupper($exchange);
        return match (true) {
            in_array($ex, ['BINANCE']) => new BinanceDataSource(),
            in_array($ex, ['ZERODHA', 'NSE', 'BSE', 'NFO', 'MCX']) => new ZerodhaDataSource(),
            in_array($ex, ['OANDA', 'FOREX']) => new OANDADataSource(),
            in_array($ex, ['YAHOO']) => new YahooDataSource(),
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

        $symbolId = (int) $request->symbol_id;
        $timeframe = $request->timeframe;

        // Try Redis cache first (populated by RunEnginesJob)
        $cached = RunEnginesJob::getCachedOverlays($symbolId, $timeframe);

        if ($cached) {
            return response()->json($cached);
        }

        // Cache miss — check if candles exist at all
        $hasCandles = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->exists();

        $emptyOverlay = [
            'signals' => [], 'orderBlocks' => [], 'fvgs' => [], 'swings' => [],
            'waveLabels' => [], 'subLegs' => [], 'formingWave' => null, 'bos' => [], 'vwap' => [],
            'patterns' => [], 'fibTargets' => [], 'nextTargets' => [],
            'timeEstimate' => [], 'liquidityPools' => [], 'oteZones' => [],
            'premiumDiscount' => [], 'inducements' => [], 'confluence' => null,
            'metadata' => ['trend' => 'neutral', 'elliott_wave' => [], 'smc' => []],
            'computed_at' => null,
        ];

        if (! $hasCandles) {
            return response()->json($emptyOverlay);
        }

        // Dispatch engine run to background queue — overlays will arrive via
        // Reverb OverlaysUpdated event. The frontend retries once after 3s
        // if it receives an empty/computing response.
        RunEnginesJob::dispatch($symbolId, $timeframe)->onQueue('engines');

        // Return empty overlay with a 'computing' flag so frontend knows to wait
        $emptyOverlay['computing'] = true;
        $emptyOverlay['computed_at'] = null;

        return response()->json($emptyOverlay);
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
            // Read from Redis cache (populated by RunEnginesJob)
            $cached = RunEnginesJob::getCachedOverlays($symbol->id, $tf);

            if ($cached && ! empty($cached['waveLabels'])) {
                $ewMeta = $cached['metadata']['elliott_wave'] ?? [];
                $trend = $cached['metadata']['trend'] ?? 'neutral';

                $trends[] = $trend;
                $tfData[$tf] = [
                    'timeframe' => $tf,
                    'degree' => $degrees[$tf] ?? $tf,
                    'wave' => $ewMeta['current_wave'] ?? null,
                    'phase' => $ewMeta['phase'] ?? null,
                    'trend' => $trend,
                    'health' => $ewMeta['health_score'] ?? 0,
                    'waveLabels' => $cached['waveLabels'],
                    'fibTargets' => $cached['fibTargets'] ?? [],
                ];
                continue;
            }

            // Cache miss — fall back to live computation for this timeframe
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

        // Trend progress: smart calculation using HTF wave + LTF forming wave awareness
        $htfWave = $tfData['1D']['wave'] ?? $tfData['4H']['wave'] ?? null;
        $htfWaveLabels = $tfData['1D']['waveLabels'] ?? $tfData['4H']['waveLabels'] ?? [];

        // Check if LTF shows a new impulse forming after correction end
        $ltfFormingNewCycle = false;
        foreach (['1H', '15M', '5M'] as $ltfKey) {
            $ltfWave = $tfData[$ltfKey]['wave'] ?? null;
            if ($htfWave === 'C' && in_array($ltfWave, ['1', '2', '3'])) {
                $ltfFormingNewCycle = true;
                break;
            }
        }

        $trendProgress = $this->estimateTrendProgress($htfWave, $htfWaveLabels, $ltfFormingNewCycle);

        return response()->json([
            'symbol' => $symbol->ticker,
            'timeframes' => $tfData,
            'htfBias' => $htfBias,
            'alignment' => $alignment . '/' . $totalTfs,
            'alignmentPct' => $totalTfs > 0 ? round($alignment / $totalTfs * 100) : 0,
            'trendProgress' => $trendProgress,
        ]);
    }

    /**
     * Calculate trend progress with context awareness.
     * Uses wave position + LTF new cycle detection for accurate progress.
     */
    private function estimateTrendProgress(?string $wave, array $waveLabels = [], bool $ltfFormingNewCycle = false): array
    {
        // If HTF is at wave C but LTF shows new impulse forming → transition state
        if ($wave === 'C' && $ltfFormingNewCycle) {
            return ['pct' => 5, 'label' => 'NEW IMPULSE FORMING', 'stage' => 'start'];
        }

        // Calculate intra-wave progress from wave labels if available
        if (! empty($waveLabels) && $wave) {
            $progress = $this->calculateIntraWaveProgress($wave, $waveLabels);
            if ($progress !== null) {
                return $progress;
            }
        }

        // Fallback: static mapping with more granular stages
        $progressMap = [
            '1' => ['pct' => 12, 'label' => 'IMPULSE STARTING', 'stage' => 'start'],
            '2' => ['pct' => 22, 'label' => 'EARLY PULLBACK', 'stage' => 'start'],
            '3' => ['pct' => 45, 'label' => 'STRONGEST MOVE', 'stage' => 'middle'],
            '4' => ['pct' => 65, 'label' => 'CONSOLIDATION', 'stage' => 'middle'],
            '5' => ['pct' => 82, 'label' => 'FINAL PUSH', 'stage' => 'end'],
            'A' => ['pct' => 35, 'label' => 'CORRECTION START', 'stage' => 'start'],
            'B' => ['pct' => 55, 'label' => 'CORRECTION BOUNCE', 'stage' => 'middle'],
            'C' => ['pct' => 80, 'label' => 'CORRECTION END', 'stage' => 'end'],
        ];

        return $progressMap[$wave] ?? ['pct' => 50, 'label' => 'ANALYZING', 'stage' => 'middle'];
    }

    /**
     * Calculate progress within the current wave using Fibonacci price ratios.
     * Returns percentage of how far through the full cycle (impulse + correction).
     */
    private function calculateIntraWaveProgress(string $currentWave, array $waveLabels): ?array
    {
        if (count($waveLabels) < 2) {
            return null;
        }

        // Map wave labels to cycle phase percentages (start → end of that wave)
        // Full cycle: 1(0-12) → 2(12-22) → 3(22-50) → 4(50-65) → 5(65-82) → A(82-88) → B(88-93) → C(93-100)
        $phaseRanges = [
            '1' => [0, 12],
            '2' => [12, 22],
            '3' => [22, 50],
            '4' => [50, 65],
            '5' => [65, 82],
            'A' => [82, 88],
            'B' => [88, 93],
            'C' => [93, 100],
        ];

        if (! isset($phaseRanges[$currentWave])) {
            return null;
        }

        [$rangeStart, $rangeEnd] = $phaseRanges[$currentWave];

        // Use midpoint of the phase range as the progress
        $pct = (int) round(($rangeStart + $rangeEnd) / 2);

        // Determine stage
        $stage = $pct < 30 ? 'start' : ($pct < 70 ? 'middle' : 'end');

        // Labels with wave context
        $labels = [
            '1' => 'IMPULSE WAVE 1',
            '2' => 'PULLBACK WAVE 2',
            '3' => 'STRONGEST WAVE 3',
            '4' => 'CONSOLIDATION WAVE 4',
            '5' => 'FINAL WAVE 5',
            'A' => 'CORRECTION WAVE A',
            'B' => 'CORRECTION WAVE B',
            'C' => 'CORRECTION WAVE C',
        ];

        return [
            'pct' => $pct,
            'label' => $labels[$currentWave] ?? 'WAVE ' . $currentWave,
            'stage' => $stage,
        ];
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
