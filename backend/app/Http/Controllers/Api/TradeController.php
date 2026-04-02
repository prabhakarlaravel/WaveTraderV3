<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\Trade;
use App\Services\AutoTradeService;
use App\Services\BlackScholesService;
use Carbon\Carbon;
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

    /**
     * Options chain: Black-Scholes theoretical pricing for paper trading.
     *
     * No auth required — this is a read-only calculation endpoint for paper traders.
     */
    public function optionsChain(Request $request): JsonResponse
    {
        $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'expiry' => 'nullable|date_format:Y-m-d',
            'iv' => 'nullable|numeric|min:0.01|max:5.0',
        ]);

        $symbol = Symbol::findOrFail($request->query('symbol_id'));

        // Get the current spot price from the latest candle close (1M timeframe preferred)
        $latestCandle = Candle::where('symbol_id', $symbol->id)
            ->whereIn('timeframe', ['1M', '5M', '15M', '1H', '1D'])
            ->orderByDesc('timestamp')
            ->first();

        if (!$latestCandle) {
            return response()->json([
                'error' => 'No candle data available for this symbol.',
            ], 422);
        }

        $spot = (float) $latestCandle->close;
        $iv = (float) ($request->query('iv') ?? 0.15);

        // Determine expiry: use provided date or calculate nearest Thursday (NSE weekly expiry)
        $expiry = $request->query('expiry')
            ? $request->query('expiry')
            : $this->nearestThursday();

        // Determine strike interval based on symbol ticker and exchange
        $strikeInterval = $this->resolveStrikeInterval($symbol, $spot);

        $bsService = new BlackScholesService();
        $atmStrike = $bsService->atmStrike($spot, $strikeInterval);
        $chain = $bsService->chain($spot, $expiry, $strikeInterval, $iv);

        return response()->json([
            'spot' => $spot,
            'expiry' => $expiry,
            'strikeInterval' => $strikeInterval,
            'atmStrike' => $atmStrike,
            'iv' => $iv,
            'symbol' => $symbol->ticker,
            'exchange' => $symbol->exchange,
            'chain' => $chain,
        ]);
    }

    /**
     * Get the nearest Thursday (NSE weekly expiry day).
     *
     * If today is Thursday, use today. Otherwise, find the next Thursday.
     */
    private function nearestThursday(): string
    {
        $today = Carbon::now('Asia/Kolkata');

        if ($today->isThursday()) {
            return $today->format('Y-m-d');
        }

        return $today->copy()->next(Carbon::THURSDAY)->format('Y-m-d');
    }

    /**
     * Resolve the strike interval based on the symbol's ticker and exchange.
     *
     * - BANKNIFTY: 100
     * - NIFTY: 50
     * - Other NSE stocks: 10
     * - Default (crypto, forex, etc.): spot * 0.005 rounded to a clean number
     */
    private function resolveStrikeInterval(Symbol $symbol, float $spot): float
    {
        $ticker = strtoupper($symbol->ticker);
        $exchange = strtoupper($symbol->exchange);

        // NSE index options (exchange may be stored as 'zerodha', 'NSE', or 'NFO')
        if (in_array($exchange, ['NSE', 'NFO', 'ZERODHA'])) {
            if (str_contains($ticker, 'BANKNIFTY') || str_contains($ticker, 'NIFTY BANK')) {
                return 100.0;
            }

            if (str_contains($ticker, 'NIFTY')) {
                return 50.0;
            }

            // NSE stocks (F&O segment)
            return 10.0;
        }

        // For other markets: calculate as 0.5% of spot, rounded to a clean number
        $raw = $spot * 0.005;

        if ($raw >= 100) {
            return round($raw / 100) * 100;
        } elseif ($raw >= 10) {
            return round($raw / 10) * 10;
        } elseif ($raw >= 1) {
            return round($raw);
        } else {
            return round($raw, 2);
        }
    }
}
