<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Signal;
use App\Models\Symbol;
use App\Models\Wave;
use App\Services\WaveHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaveController extends Controller
{
    public function index(Request $request, string $symbol): JsonResponse
    {
        $timeframe = $request->query('timeframe', '1H');

        $waves = Wave::whereHas('symbol', fn ($q) => $q->where('ticker', $symbol))
            ->where('timeframe', $timeframe)
            ->orderBy('start_time')
            ->get();

        return response()->json($waves);
    }

    public function health(string $symbol): JsonResponse
    {
        $waves = Wave::whereHas('symbol', fn ($q) => $q->where('ticker', $symbol))
            ->primary()
            ->latest()
            ->get()
            ->groupBy('timeframe')
            ->map(fn ($group) => [
                'health_score' => $group->avg('health_score'),
                'count' => $group->count(),
            ]);

        return response()->json($waves);
    }

    public function healthDashboard(Request $request, WaveHealthService $service): JsonResponse
    {
        $symbolId = $request->query('symbol_id');
        if ($symbolId) {
            return response()->json($service->dashboard((int) $symbolId));
        }

        // All active symbols
        $symbols = Symbol::active()->get();
        $results = [];
        foreach ($symbols as $symbol) {
            $results[$symbol->ticker] = $service->dashboard($symbol->id);
        }

        return response()->json($results);
    }

    public function signals(Request $request, string $symbol): JsonResponse
    {
        $signals = Signal::whereHas('symbol', fn ($q) => $q->where('ticker', $symbol))
            ->when($request->query('engine'), fn ($q, $engine) => $q->where('engine', $engine))
            ->when($request->query('timeframe'), fn ($q, $tf) => $q->where('timeframe', $tf))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($signals);
    }
}
