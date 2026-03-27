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
use App\Models\Candle;
use App\Models\FVG;
use App\Models\OrderBlock;
use App\Models\Signal;
use App\Models\Symbol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartController extends Controller
{
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
