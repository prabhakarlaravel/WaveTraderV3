<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backtest;
use App\Services\BacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BacktestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Backtest::orderByDesc('created_at')->paginate(20));
    }

    public function store(Request $request, BacktestService $service): JsonResponse
    {
        $data = $request->validate([
            'symbol_id' => 'required|exists:symbols,id',
            'timeframe' => 'required|in:1M,5M,15M,1H,4H,1D',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after:from_date',
            'mode' => 'required|in:replay,auto,manual',
            'config' => 'nullable|array',
        ]);

        $backtest = Backtest::create($data);

        // Run backtest synchronously (for now — can be queued later for large datasets)
        $results = $service->run($backtest);

        $backtest->update(['results_json' => $results]);

        return response()->json($backtest->fresh(), 201);
    }

    public function show(Backtest $backtest): JsonResponse
    {
        return response()->json($backtest);
    }
}
