<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candle;
use App\Models\Symbol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UdfController extends Controller
{
    private const RESOLUTION_MAP = [
        '1' => '1M', '5' => '5M', '15' => '15M',
        '60' => '1H', '240' => '4H', 'D' => '1D', '1D' => '1D',
    ];

    public function config(): JsonResponse
    {
        return response()->json([
            'supported_resolutions' => ['1', '5', '15', '60', '240', 'D'],
            'supports_group_request' => false,
            'supports_marks' => true,
            'supports_search' => true,
            'supports_timescale_marks' => true,
            'supports_time' => true,
            'exchanges' => [
                ['value' => 'zerodha', 'name' => 'Zerodha', 'desc' => 'NSE/BSE'],
                ['value' => 'binance', 'name' => 'Binance', 'desc' => 'Crypto'],
                ['value' => 'oanda', 'name' => 'OANDA', 'desc' => 'Forex'],
            ],
        ]);
    }

    public function symbols(Request $request): JsonResponse
    {
        $symbol = Symbol::where('ticker', $request->query('symbol'))->first();

        if (! $symbol) {
            return response()->json(['s' => 'no_data']);
        }

        return response()->json([
            'name' => $symbol->ticker,
            'full_name' => $symbol->name,
            'description' => $symbol->name,
            'type' => $symbol->type,
            'session' => $symbol->session ?? '0915-1530',
            'timezone' => $symbol->timezone,
            'exchange' => $symbol->exchange,
            'minmov' => 1,
            'pricescale' => 100,
            'has_intraday' => true,
            'has_daily' => true,
            'supported_resolutions' => ['1', '5', '15', '60', '240', 'D'],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->query('query', '');

        $results = Symbol::where('ticker', 'ilike', "%{$query}%")
            ->orWhere('name', 'ilike', "%{$query}%")
            ->limit($request->query('limit', 30))
            ->get()
            ->map(fn ($s) => [
                'symbol' => $s->ticker,
                'full_name' => $s->name,
                'description' => $s->name,
                'exchange' => $s->exchange,
                'type' => $s->type,
            ]);

        return response()->json($results);
    }

    public function history(Request $request): JsonResponse
    {
        $symbol = Symbol::where('ticker', $request->query('symbol'))->first();

        if (! $symbol) {
            return response()->json(['s' => 'error', 'errmsg' => 'Unknown symbol']);
        }

        $resolution = $request->query('resolution', '1');
        $timeframe = self::RESOLUTION_MAP[$resolution] ?? '1M';

        $candles = Candle::forSymbol($symbol->id, $timeframe)
            ->where('timestamp', '>=', gmdate('Y-m-d H:i:s', (int) $request->query('from')))
            ->where('timestamp', '<=', gmdate('Y-m-d H:i:s', (int) $request->query('to')))
            ->orderBy('timestamp')
            ->get();

        if ($candles->isEmpty()) {
            return response()->json(['s' => 'no_data']);
        }

        return response()->json([
            's' => 'ok',
            't' => $candles->pluck('timestamp')->map(fn ($t) => $t->timestamp)->values(),
            'o' => $candles->pluck('open')->map(fn ($v) => (float) $v)->values(),
            'h' => $candles->pluck('high')->map(fn ($v) => (float) $v)->values(),
            'l' => $candles->pluck('low')->map(fn ($v) => (float) $v)->values(),
            'c' => $candles->pluck('close')->map(fn ($v) => (float) $v)->values(),
            'v' => $candles->pluck('volume')->map(fn ($v) => (float) $v)->values(),
        ]);
    }

    public function marks(): JsonResponse
    {
        // TODO: Return signal markers on price bars
        return response()->json([]);
    }

    public function timescaleMarks(): JsonResponse
    {
        // TODO: Return Elliott Wave labels on time axis
        return response()->json([]);
    }

    public function streaming(Request $request): StreamedResponse
    {
        // SSE endpoint — requires Nginx proxy_buffering off (rule #4)
        return response()->stream(function () use ($request) {
            $symbol = $request->query('symbol');

            // TODO: Subscribe to Redis channel "candles:{symbol}:{timeframe}"
            // and push SSE events to the client

            echo "data: " . json_encode(['status' => 'connected', 'symbol' => $symbol]) . "\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
