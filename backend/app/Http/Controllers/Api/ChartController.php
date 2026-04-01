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
            'timeframe' => 'nullable|string|in:1M,5M,15M,1H,4H,1D',
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

            // Cache miss — dispatch background engine run instead of blocking.
            // Return empty row for this TF; it will populate on next 30s cycle.
            RunEnginesJob::dispatch($symbol->id, $tf)->onQueue('engines');

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
        }

        // Calculate alignment
        $bullCount = count(array_filter($trends, fn ($t) => $t === 'bullish'));
        $bearCount = count(array_filter($trends, fn ($t) => $t === 'bearish'));
        $totalTfs = count(self::TIMEFRAMES);
        $alignment = max($bullCount, $bearCount);
        $htfBias = $bullCount > $bearCount ? 'BULL' : ($bearCount > $bullCount ? 'BEAR' : 'NEUTRAL');

        // Trend progress: dynamic calculation using price position within wave range
        $htfTf = ($tfData['1D']['wave'] ?? null) ? '1D' : (($tfData['4H']['wave'] ?? null) ? '4H' : null);
        $htfRow = $htfTf ? $tfData[$htfTf] : null;
        $trendProgress = $this->estimateTrendProgress($htfRow);

        // Compute confluence for the response (single source of truth for frontend)
        $activeTfCached = RunEnginesJob::getCachedOverlays($symbol->id, $request->query('timeframe', '5M'));
        $confluence = $activeTfCached['confluence'] ?? null;

        return response()->json([
            'symbol' => $symbol->ticker,
            'timeframes' => $tfData,
            'htfBias' => $htfBias,
            'alignment' => $alignment . '/' . $totalTfs,
            'alignmentPct' => $totalTfs > 0 ? round($alignment / $totalTfs * 100) : 0,
            'trendProgress' => $trendProgress,
            'confluence' => $confluence,
        ]);
    }

    /**
     * Estimate trend progress using ACTUAL price position within the wave range.
     * Each wave has a base % range; we interpolate within that range using price.
     *
     * Wave cycle:  1(0-12%) → 2(12-22%) → 3(22-55%) → 4(55-65%) → 5(65-80%) → A(80-87%) → B(87-93%) → C(93-100%)
     *
     * @param  array|null  $htfRow  The HTF timeframe row with wave, waveLabels, etc.
     */
    private function estimateTrendProgress(?array $htfRow): array
    {
        if (! $htfRow || empty($htfRow['wave'])) {
            return ['pct' => 50, 'label' => 'ANALYZING', 'stage' => 'middle'];
        }

        $wave = $htfRow['wave'];
        $labels = $htfRow['waveLabels'] ?? [];

        // Base range for each wave position in the full cycle
        $waveRanges = [
            '1' => ['min' => 0,  'max' => 12, 'label' => 'IMPULSE START',     'stage' => 'start'],
            '2' => ['min' => 12, 'max' => 22, 'label' => 'EARLY PULLBACK',    'stage' => 'start'],
            '3' => ['min' => 22, 'max' => 55, 'label' => 'IMPULSE MIDDLE',    'stage' => 'middle'],
            '4' => ['min' => 55, 'max' => 65, 'label' => 'LATE PULLBACK',     'stage' => 'middle'],
            '5' => ['min' => 65, 'max' => 80, 'label' => 'IMPULSE END',       'stage' => 'end'],
            'A' => ['min' => 80, 'max' => 87, 'label' => 'CORRECTION START',  'stage' => 'start'],
            'B' => ['min' => 87, 'max' => 93, 'label' => 'CORRECTION MIDDLE', 'stage' => 'middle'],
            'C' => ['min' => 93, 'max' => 100,'label' => 'CORRECTION END',    'stage' => 'end'],
        ];

        $range = $waveRanges[$wave] ?? null;
        if (! $range) {
            return ['pct' => 50, 'label' => 'ANALYZING', 'stage' => 'middle'];
        }

        // Try to calculate intra-wave progress using price position
        $intraProgress = 0.5; // default: midpoint of the wave range
        if (count($labels) >= 2) {
            $intraProgress = $this->calculateIntraWaveProgress($wave, $labels);
        }

        // Interpolate within the wave's % range
        $pct = (int) round($range['min'] + ($range['max'] - $range['min']) * $intraProgress);
        $pct = max($range['min'], min($range['max'], $pct));

        return [
            'pct' => $pct,
            'label' => $range['label'],
            'stage' => $range['stage'],
        ];
    }

    /**
     * Calculate how far price has traveled within the current wave (0.0 to 1.0).
     * Uses the wave start price and Fibonacci extension/retracement targets.
     */
    private function calculateIntraWaveProgress(string $wave, array $labels): float
    {
        if (empty($labels)) {
            return 0.5;
        }

        // Find the current wave's start label and the previous wave's label
        $currentLabel = null;
        $prevLabel = null;
        for ($i = count($labels) - 1; $i >= 0; $i--) {
            if ($labels[$i]['label'] === $wave) {
                $currentLabel = $labels[$i];
                if ($i > 0) {
                    $prevLabel = $labels[$i - 1];
                }
                break;
            }
        }

        // If we can't find the wave labels, use last label as current price proxy
        if (! $currentLabel && ! empty($labels)) {
            $currentLabel = end($labels);
            $prevLabel = count($labels) > 1 ? $labels[count($labels) - 2] : null;
        }

        if (! $prevLabel || ! $currentLabel) {
            return 0.5;
        }

        $startPrice = (float) $prevLabel['price'];
        $currentPrice = (float) $currentLabel['price'];

        // Estimate expected wave target based on Elliott Wave rules
        $expectedMove = $this->estimateWaveTarget($wave, $labels);
        if ($expectedMove <= 0) {
            return 0.5;
        }

        $actualMove = abs($currentPrice - $startPrice);
        $progress = min(1.0, $actualMove / $expectedMove);

        return $progress;
    }

    /**
     * Estimate the expected price move for a wave based on typical Fibonacci ratios.
     */
    private function estimateWaveTarget(string $wave, array $labels): float
    {
        // Find wave 1 length as base reference (if available)
        $wave1Start = null;
        $wave1End = null;
        foreach ($labels as $l) {
            if ($l['label'] === '1') {
                $wave1End = (float) $l['price'];
            }
        }
        // Wave 1 start is the label before wave 1
        for ($i = 0; $i < count($labels); $i++) {
            if ($labels[$i]['label'] === '1' && $i > 0) {
                $wave1Start = (float) $labels[$i - 1]['price'];
                break;
            }
        }

        $wave1Length = ($wave1Start && $wave1End) ? abs($wave1End - $wave1Start) : 0;

        // Use typical Fibonacci ratios for each wave
        return match ($wave) {
            '1' => $wave1Length > 0 ? $wave1Length : 100,              // self-reference: use actual
            '2' => $wave1Length * 0.618,                               // retraces 50-61.8% of W1
            '3' => $wave1Length * 1.618,                               // extends 161.8% of W1
            '4' => $wave1Length * 0.382,                               // retraces 38.2% of W3
            '5' => $wave1Length * 1.0,                                 // equals W1
            'A' => $wave1Length * 0.618,                               // 61.8% of impulse
            'B' => $wave1Length * 0.382,                               // 38.2% retrace of A
            'C' => $wave1Length * 1.0,                                 // equals A typically
            default => $wave1Length > 0 ? $wave1Length : 100,
        };
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
