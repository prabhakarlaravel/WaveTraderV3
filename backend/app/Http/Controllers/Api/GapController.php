<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataGap;
use App\Models\Symbol;
use App\Services\GapDetection\GapDetectorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $gaps = DataGap::with('symbol')
            ->when($request->query('symbol_id'), fn ($q, $id) => $q->where('symbol_id', $id))
            ->when($request->query('timeframe'), fn ($q, $tf) => $q->where('timeframe', $tf))
            ->orderByDesc('gap_start')
            ->paginate(50);

        return response()->json($gaps);
    }

    /**
     * Smart scan: detect gaps using market-specific detector.
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'nullable|in:1M,5M,15M,1H,4H,1D',
        ]);

        $symbol = Symbol::findOrFail($request->symbol_id);
        $detector = GapDetectorResolver::resolve($symbol);

        Log::info("GapController: scanning {$symbol->ticker} using {$detector->getMarketType()} detector");

        return response()->json($detector->scan($symbol));
    }

    /**
     * Fill gaps for a specific timeframe using market-specific strategy.
     */
    public function fill(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|in:1M,5M,15M,1H,4H,1D',
        ]);

        $symbol = Symbol::findOrFail($request->symbol_id);
        $timeframe = $request->timeframe;
        $detector = GapDetectorResolver::resolve($symbol);

        Log::info("GapController: filling {$symbol->ticker} [{$timeframe}] using {$detector->getMarketType()} detector");

        $filledCount = $detector->fill($symbol, $timeframe);

        return response()->json([
            'success'       => $filledCount > 0,
            'message'       => $filledCount > 0
                ? "Filled {$filledCount} candles for {$timeframe}"
                : "No candles could be filled. May be a holiday or exchange unreachable.",
            'filled'        => $filledCount,
            'marketType'    => $detector->getMarketType(),
        ]);
    }

    public function health(): JsonResponse
    {
        $symbols = Symbol::active()->get();
        $report = [];

        foreach ($symbols as $symbol) {
            $totalGaps = DataGap::where('symbol_id', $symbol->id)->count();
            $unfilledGaps = DataGap::where('symbol_id', $symbol->id)->unfilled()->count();

            $report[] = [
                'symbol' => $symbol->ticker,
                'total_gaps' => $totalGaps,
                'unfilled_gaps' => $unfilledGaps,
                'health_pct' => $totalGaps > 0 ? round((1 - $unfilledGaps / $totalGaps) * 100, 1) : 100,
            ];
        }

        return response()->json($report);
    }
}
