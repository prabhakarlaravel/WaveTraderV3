<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceDataSource implements DataSourceInterface
{
    private const BASE_URL = 'https://api.binance.com/api/v3';
    private const MAX_BARS_PER_REQUEST = 1000;
    private const MAX_RETRIES = 3;

    private const TIMEFRAME_MAP = [
        '1M' => '1m',
        '5M' => '5m',
        '15M' => '15m',
        '1H' => '1h',
        '4H' => '4h',
        '1D' => '1d',
    ];

    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        $interval = self::TIMEFRAME_MAP[$timeframe] ?? '1m';
        $allCandles = collect();

        $startTime = $from->getTimestampMs();
        $endTime = $to->getTimestampMs();

        while ($startTime < $endTime) {
            $response = $this->request('/klines', [
                'symbol' => $symbol,
                'interval' => $interval,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'limit' => self::MAX_BARS_PER_REQUEST,
            ]);

            if (empty($response)) {
                break;
            }

            foreach ($response as $row) {
                $allCandles->push([
                    'timeframe' => $timeframe,
                    'timestamp' => Carbon::createFromTimestampMs((int) $row[0])->utc()->format('Y-m-d H:i:sP'),
                    'open' => (float) $row[1],
                    'high' => (float) $row[2],
                    'low' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                ]);
            }

            // Advance past the last candle's close time
            $lastCloseTime = (int) end($response)[6];
            $startTime = $lastCloseTime + 1;

            // If we got fewer than limit, we've reached the end
            if (count($response) < self::MAX_BARS_PER_REQUEST) {
                break;
            }
        }

        Log::info("Binance: fetched {$allCandles->count()} {$timeframe} candles for {$symbol}");

        return $allCandles;
    }

    public function supportsRealtime(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'binance';
    }

    private function request(string $endpoint, array $params): array
    {
        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            $response = Http::timeout(30)->get(self::BASE_URL . $endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 429) {
                $wait = (int) pow(2, $retries + 1);
                Log::warning("Binance rate limited, retrying in {$wait}s...");
                sleep($wait);
                $retries++;
                continue;
            }

            Log::error("Binance API error: {$response->status()} — {$response->body()}");

            return [];
        }

        Log::error('Binance API: max retries exceeded');

        return [];
    }
}
