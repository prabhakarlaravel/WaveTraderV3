<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataGap;
use App\Models\Symbol;
use App\Services\GapDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function scan(Request $request, GapDetectionService $gapService): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'nullable|in:1M,5M,15M,1H,4H,1D',
        ]);

        $symbol = Symbol::findOrFail($request->symbol_id);

        if ($request->timeframe) {
            $gaps = $gapService->detect($symbol, $request->timeframe);

            return response()->json([
                'message' => "Found {$gaps->count()} gaps",
                'gaps' => $gaps,
            ]);
        }

        // Smart scan: all TFs with visual timeline
        return response()->json($gapService->smartScan($symbol));
    }

    public function fill(Request $request, GapDetectionService $gapService): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|in:1M,5M,15M,1H,4H,1D',
        ]);

        $symbol = Symbol::findOrFail($request->symbol_id);
        $gaps = DataGap::where('symbol_id', $symbol->id)
            ->where('timeframe', $request->timeframe)
            ->unfilled()
            ->get();

        $filledCount = $gapService->fill($symbol, $request->timeframe, $gaps);

        return response()->json(['message' => "Filled {$gaps->count()} gaps ({$filledCount} candles fetched)"]);
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
