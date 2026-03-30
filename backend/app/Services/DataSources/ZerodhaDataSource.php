<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZerodhaDataSource implements DataSourceInterface
{
    private const BASE_URL = 'https://api.kite.trade';
    private const MAX_DAYS_PER_REQUEST = 60;
    private const MAX_RETRIES = 3;
    private const RATE_LIMIT_DELAY_MS = 340; // 3 req/sec → 333ms between requests

    private const TIMEFRAME_MAP = [
        '1M' => 'minute',
        '5M' => '5minute',
        '15M' => '15minute',
        '1H' => '60minute',
        '4H' => '4hour',   // Not natively supported — derived from 60minute
        '1D' => 'day',
    ];

    // Instrument token cache (symbol → token)
    private array $instrumentTokens = [];

    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        $apiKey = Setting::get('zerodha_api_key');
        $accessToken = Setting::get('zerodha_access_token');

        if (! $apiKey || ! $accessToken) {
            Log::warning('Zerodha: API key or access token not configured');

            return collect();
        }

        $interval = self::TIMEFRAME_MAP[$timeframe] ?? 'minute';

        // 4H not natively supported — fetch 60minute and aggregate
        if ($timeframe === '4H') {
            return $this->fetch4H($symbol, $from, $to, $apiKey, $accessToken);
        }

        $instrumentToken = $this->resolveInstrumentToken($symbol, $apiKey, $accessToken);
        if (! $instrumentToken) {
            Log::error("Zerodha: could not resolve instrument token for {$symbol}");

            return collect();
        }

        $allCandles = collect();
        $chunkFrom = $from->copy();

        // KiteConnect allows max 60 days per historical data request
        while ($chunkFrom->lt($to)) {
            $chunkTo = $chunkFrom->copy()->addDays(self::MAX_DAYS_PER_REQUEST);
            if ($chunkTo->gt($to)) {
                $chunkTo = $to->copy();
            }

            $candles = $this->requestCandles(
                $instrumentToken, $interval,
                $chunkFrom->format('Y-m-d H:i:s'),
                $chunkTo->format('Y-m-d H:i:s'),
                $apiKey, $accessToken, $timeframe
            );

            $allCandles = $allCandles->merge($candles);
            $chunkFrom = $chunkTo->copy()->addSecond();

            // Rate limiting: 3 requests/second
            usleep(self::RATE_LIMIT_DELAY_MS * 1000);
        }

        Log::info("Zerodha: fetched {$allCandles->count()} {$timeframe} candles for {$symbol}");

        return $allCandles;
    }

    public function supportsRealtime(): bool
    {
        return true; // KTicker WebSocket
    }

    public function getName(): string
    {
        return 'zerodha';
    }

    /**
     * Fetch 4H candles by aggregating 60-minute data.
     */
    private function fetch4H(string $symbol, Carbon $from, Carbon $to, string $apiKey, string $accessToken): Collection
    {
        // Fetch 60-minute candles
        $hourlyCandles = $this->fetchCandles($symbol, '1H', $from, $to);

        if ($hourlyCandles->isEmpty()) {
            return collect();
        }

        // Aggregate into 4H buckets
        $aggregated = collect();
        $bucket = [];
        $bucketStart = null;

        foreach ($hourlyCandles as $candle) {
            $ts = Carbon::parse($candle['timestamp']);
            $bucketHour = (int) floor($ts->hour / 4) * 4;
            $currentBucketStart = $ts->copy()->setTime($bucketHour, 0);

            if ($bucketStart === null || ! $currentBucketStart->eq($bucketStart)) {
                if (! empty($bucket)) {
                    $aggregated->push($this->aggregateBucket($bucket, '4H'));
                }
                $bucket = [$candle];
                $bucketStart = $currentBucketStart;
            } else {
                $bucket[] = $candle;
            }
        }

        if (! empty($bucket)) {
            $aggregated->push($this->aggregateBucket($bucket, '4H'));
        }

        return $aggregated;
    }

    private function aggregateBucket(array $candles, string $timeframe): array
    {
        return [
            'timeframe' => $timeframe,
            'timestamp' => $candles[0]['timestamp'],
            'open' => $candles[0]['open'],
            'high' => max(array_column($candles, 'high')),
            'low' => min(array_column($candles, 'low')),
            'close' => end($candles)['close'],
            'volume' => array_sum(array_column($candles, 'volume')),
        ];
    }

    /**
     * Make a single historical data request to KiteConnect.
     */
    private function requestCandles(
        string $instrumentToken, string $interval,
        string $from, string $to,
        string $apiKey, string $accessToken, string $timeframe
    ): Collection {
        $url = self::BASE_URL . "/instruments/historical/{$instrumentToken}/{$interval}";

        $retries = 0;
        while ($retries < self::MAX_RETRIES) {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Kite-Version' => '3',
                    'Authorization' => "token {$apiKey}:{$accessToken}",
                ])
                ->get($url, [
                    'from' => $from,
                    'to' => $to,
                    'continuous' => 0,
                    'oi' => 0,
                ]);

            if ($response->successful()) {
                $data = $response->json('data.candles', []);

                return collect($data)->map(fn (array $row) => [
                    'timeframe' => $timeframe,
                    'timestamp' => Carbon::parse($row[0])->toDateTimeString(),
                    'open' => (float) $row[1],
                    'high' => (float) $row[2],
                    'low' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                ]);
            }

            if ($response->status() === 429) {
                $wait = (int) pow(2, $retries + 1);
                Log::warning("Zerodha rate limited, retrying in {$wait}s...");
                sleep($wait);
                $retries++;

                continue;
            }

            if ($response->status() === 403) {
                Log::error('Zerodha: access token expired or invalid. Run token renewal.');

                return collect();
            }

            Log::error("Zerodha API error: {$response->status()} — {$response->body()}");

            return collect();
        }

        return collect();
    }

    // Cached per-exchange instrument CSV (downloaded once per process lifetime)
    private array $instrumentCsvCache = [];

    /**
     * Resolve instrument token from trading symbol.
     * Zerodha requires instrument_token (numeric ID) for historical data API.
     */
    private function resolveInstrumentToken(string $symbol, string $apiKey, string $accessToken): ?string
    {
        if (isset($this->instrumentTokens[$symbol])) {
            return $this->instrumentTokens[$symbol];
        }

        // Check if stored in settings (DB cache)
        $settingsKey = 'zerodha_instrument_' . str_replace(' ', '_', $symbol);
        $cached = Setting::get($settingsKey);
        if ($cached) {
            $this->instrumentTokens[$symbol] = $cached;

            return $cached;
        }

        $exchange = $this->guessExchange($symbol);

        // Download instruments CSV once per exchange, then cache in memory
        if (! isset($this->instrumentCsvCache[$exchange])) {
            Log::info("Zerodha: downloading {$exchange} instruments list...");

            $response = Http::timeout(60)
                ->withHeaders([
                    'X-Kite-Version' => '3',
                    'Authorization' => "token {$apiKey}:{$accessToken}",
                ])
                ->get(self::BASE_URL . '/instruments', ['exchange' => $exchange]);

            if (! $response->successful()) {
                Log::error("Zerodha: failed to fetch {$exchange} instruments — {$response->status()}");

                return null;
            }

            // Parse entire CSV into a lookup map: tradingsymbol → instrument_token
            $map = [];
            $lines = explode("\n", $response->body());
            foreach ($lines as $line) {
                $cols = str_getcsv($line);
                // CSV columns: instrument_token, exchange_token, tradingsymbol, name, ...
                if (isset($cols[2]) && $cols[0] !== 'instrument_token') {
                    $map[strtoupper($cols[2])] = $cols[0];
                }
            }

            $this->instrumentCsvCache[$exchange] = $map;
            Log::info("Zerodha: cached " . count($map) . " {$exchange} instruments");

            usleep(self::RATE_LIMIT_DELAY_MS * 1000);
        }

        $lookup = $this->instrumentCsvCache[$exchange];
        $token = $lookup[strtoupper($symbol)] ?? null;

        if ($token) {
            $this->instrumentTokens[$symbol] = $token;
            Setting::set($settingsKey, $token, 'exchange');
            Log::info("Zerodha: resolved {$symbol} → instrument_token {$token}");

            return $token;
        }

        Log::error("Zerodha: instrument token not found for {$symbol} in {$exchange} instruments");

        return null;
    }

    private function guessExchange(string $symbol): string
    {
        $symbol = strtoupper($symbol);

        if (str_contains($symbol, 'FUT') || str_contains($symbol, 'CE') || str_contains($symbol, 'PE')) {
            return 'NFO';
        }
        if (in_array($symbol, ['SENSEX', 'BANKEX'], true)) {
            return 'BSE';
        }
        if (str_contains($symbol, 'GOLD') || str_contains($symbol, 'SILVER') || str_contains($symbol, 'CRUDE')) {
            return 'MCX';
        }

        return 'NSE';
    }
}
