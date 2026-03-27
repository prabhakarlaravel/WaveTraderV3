<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YahooDataSource implements DataSourceInterface
{
    // Yahoo Finance unofficial API — unreliable, use as fallback only
    private const BASE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart';

    private const TIMEFRAME_MAP = [
        '1M' => '1m',
        '5M' => '5m',
        '15M' => '15m',
        '1H' => '1h',
        '4H' => '4h',
        '1D' => '1d',
    ];

    // Yahoo limits: max 60 days for intraday
    private const MAX_RANGE_MAP = [
        '1M' => '7d',
        '5M' => '60d',
        '15M' => '60d',
        '1H' => '730d',
        '4H' => '730d',
        '1D' => '10y',
    ];

    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        $interval = self::TIMEFRAME_MAP[$timeframe] ?? '1d';
        $range = self::MAX_RANGE_MAP[$timeframe] ?? '60d';

        // Yahoo uses different symbol formats
        $yahooSymbol = $this->formatSymbol($symbol);

        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ])
            ->get(self::BASE_URL . "/{$yahooSymbol}", [
                'interval' => $interval,
                'range' => $range,
                'events' => 'history',
            ]);

        if (! $response->successful()) {
            Log::warning("Yahoo Finance API error: {$response->status()} for {$symbol}");

            return collect();
        }

        $result = $response->json('chart.result.0', []);
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        if (empty($timestamps)) {
            return collect();
        }

        $candles = collect();
        for ($i = 0; $i < count($timestamps); $i++) {
            $open = $quote['open'][$i] ?? null;
            $high = $quote['high'][$i] ?? null;
            $low = $quote['low'][$i] ?? null;
            $close = $quote['close'][$i] ?? null;
            $volume = $quote['volume'][$i] ?? 0;

            if ($open === null || $high === null || $low === null || $close === null) {
                continue;
            }

            $ts = Carbon::createFromTimestamp($timestamps[$i]);
            if ($ts->lt($from) || $ts->gt($to)) {
                continue;
            }

            $candles->push([
                'timeframe' => $timeframe,
                'timestamp' => $ts->toDateTimeString(),
                'open' => (float) $open,
                'high' => (float) $high,
                'low' => (float) $low,
                'close' => (float) $close,
                'volume' => (float) $volume,
            ]);
        }

        Log::info("Yahoo: fetched {$candles->count()} {$timeframe} candles for {$symbol}");

        return $candles;
    }

    public function supportsRealtime(): bool
    {
        return false;
    }

    public function getName(): string
    {
        return 'yahoo';
    }

    /**
     * Convert symbol to Yahoo Finance format.
     * MCX Gold → GC=F, Silver → SI=F, Crude → CL=F
     * NSE stocks → SYMBOL.NS, BSE → SYMBOL.BO
     */
    private function formatSymbol(string $symbol): string
    {
        $map = [
            'GOLD' => 'GC=F',
            'SILVER' => 'SI=F',
            'CRUDEOIL' => 'CL=F',
            'NATURALGAS' => 'NG=F',
            'COPPER' => 'HG=F',
        ];

        if (isset($map[strtoupper($symbol)])) {
            return $map[strtoupper($symbol)];
        }

        // If it looks like an Indian stock, add .NS suffix
        if (preg_match('/^[A-Z]+$/', $symbol) && strlen($symbol) <= 10) {
            return "{$symbol}.NS";
        }

        return $symbol;
    }
}
