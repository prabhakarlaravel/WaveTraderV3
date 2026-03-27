<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trade;
use App\Services\AutoTradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TradeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $trades = Trade::with('symbol')
            ->where('user_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('engine'), fn ($q, $e) => $q->where('engine', $e))
            ->when($request->query('auto'), fn ($q) => $q->where('auto_trade', true))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($trades);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'type' => 'required|in:long,short',
            'entry_price' => 'required|numeric',
            'quantity' => 'required|numeric',
            'sl' => 'nullable|numeric',
            'tp' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $trade = Trade::create([...$data, 'user_id' => $request->user()->id, 'status' => 'open']);

        return response()->json($trade, 201);
    }

    public function show(Trade $trade): JsonResponse
    {
        return response()->json($trade->load('symbol'));
    }

    public function update(Request $request, Trade $trade): JsonResponse
    {
        $data = $request->validate([
            'exit_price' => 'nullable|numeric',
            'sl' => 'nullable|numeric',
            'tp' => 'nullable|numeric',
            'status' => 'nullable|in:open,closed,cancelled',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $trade->update($data);

        if ($trade->status === 'closed' && $trade->exit_price) {
            $multiplier = $trade->type === 'long' ? 1 : -1;
            $trade->pnl = ($trade->exit_price - $trade->entry_price) * $trade->quantity * $multiplier;
            $trade->save();
        }

        return response()->json($trade);
    }

    public function destroy(Trade $trade): JsonResponse
    {
        $trade->delete();

        return response()->json(null, 204);
    }

    /**
     * Auto-trade: evaluate current market and open trade if confluence is high enough.
     */
    public function autoTrade(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|in:1M,5M,15M,1H,4H,1D',
            'min_confluence' => 'nullable|integer|min:0|max:100',
        ]);

        $service = new AutoTradeService(
            minConfluence: $request->min_confluence ?? 60,
        );

        $result = $service->evaluate(
            $request->user()->id,
            $request->symbol_id,
            $request->timeframe,
        );

        return response()->json($result);
    }

    /**
     * Analytics: P&L breakdown by engine, wave position, time period.
     */
    public function analytics(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $closedTrades = Trade::where('user_id', $userId)
            ->where('status', 'closed')
            ->get();

        $totalPnl = $closedTrades->sum('pnl');
        $winCount = $closedTrades->where('pnl', '>', 0)->count();
        $totalCount = $closedTrades->count();
        $winRate = $totalCount > 0 ? round($winCount / $totalCount * 100, 1) : 0;

        // By engine
        $byEngine = $closedTrades->groupBy('engine')->map(fn ($trades) => [
            'count' => $trades->count(),
            'pnl' => round($trades->sum('pnl'), 2),
            'win_rate' => $trades->count() > 0
                ? round($trades->where('pnl', '>', 0)->count() / $trades->count() * 100, 1)
                : 0,
            'avg_pnl' => $trades->count() > 0 ? round($trades->avg('pnl'), 2) : 0,
        ]);

        // By wave position
        $byWave = $closedTrades->groupBy('wave_position')->map(fn ($trades) => [
            'count' => $trades->count(),
            'pnl' => round($trades->sum('pnl'), 2),
            'win_rate' => $trades->count() > 0
                ? round($trades->where('pnl', '>', 0)->count() / $trades->count() * 100, 1)
                : 0,
        ]);

        // By timeframe
        $byTimeframe = $closedTrades->groupBy('timeframe')->map(fn ($trades) => [
            'count' => $trades->count(),
            'pnl' => round($trades->sum('pnl'), 2),
            'win_rate' => $trades->count() > 0
                ? round($trades->where('pnl', '>', 0)->count() / $trades->count() * 100, 1)
                : 0,
        ]);

        // Auto vs manual
        $autoTrades = $closedTrades->where('auto_trade', true);
        $manualTrades = $closedTrades->where('auto_trade', false);

        return response()->json([
            'summary' => [
                'total_trades' => $totalCount,
                'total_pnl' => round($totalPnl, 2),
                'win_rate' => $winRate,
                'open_trades' => Trade::where('user_id', $userId)->open()->count(),
            ],
            'by_engine' => $byEngine,
            'by_wave' => $byWave,
            'by_timeframe' => $byTimeframe,
            'auto_vs_manual' => [
                'auto' => [
                    'count' => $autoTrades->count(),
                    'pnl' => round($autoTrades->sum('pnl'), 2),
                    'win_rate' => $autoTrades->count() > 0
                        ? round($autoTrades->where('pnl', '>', 0)->count() / $autoTrades->count() * 100, 1)
                        : 0,
                ],
                'manual' => [
                    'count' => $manualTrades->count(),
                    'pnl' => round($manualTrades->sum('pnl'), 2),
                    'win_rate' => $manualTrades->count() > 0
                        ? round($manualTrades->where('pnl', '>', 0)->count() / $manualTrades->count() * 100, 1)
                        : 0,
                ],
            ],
        ]);
    }
}
