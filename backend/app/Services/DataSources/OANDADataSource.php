<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OANDADataSource implements DataSourceInterface
{
    private const PRACTICE_URL = 'https://api-fxpractice.oanda.com';
    private const LIVE_URL = 'https://api-fxtrade.oanda.com';
    private const MAX_BARS_PER_REQUEST = 5000;
    private const MAX_RETRIES = 3;

    // OANDA granularity mapping
    private const TIMEFRAME_MAP = [
        '1M' => 'M1',
        '5M' => 'M5',
        '15M' => 'M15',
        '1H' => 'H1',
        '4H' => 'H4',
        '1D' => 'D',
    ];

    // Forex market is closed on weekends
    private const WEEKEND_CLOSE_DAY = 5;  // Friday
    private const WEEKEND_CLOSE_HOUR = 22; // 10pm UTC Friday
    private const WEEKEND_OPEN_DAY = 0;    // Sunday
    private const WEEKEND_OPEN_HOUR = 22;  // 10pm UTC Sunday

    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection
    {
        $accountId = Setting::get('oanda_account_id');
        $token = Setting::get('oanda_bearer_token');
        $mode = Setting::get('oanda_mode', 'practice');

        if (! $accountId || ! $token) {
            Log::warning('OANDA: Account ID or Bearer Token not configured');

            return collect();
        }

        $baseUrl = $mode === 'live' ? self::LIVE_URL : self::PRACTICE_URL;
        $granularity = self::TIMEFRAME_MAP[$timeframe] ?? 'M1';

        // Convert symbol format: EURUSD → EUR_USD (OANDA uses underscore)
        $instrument = $this->formatInstrument($symbol);

        $allCandles = collect();
        $currentFrom = $from->copy();

        while ($currentFrom->lt($to)) {
            // Skip weekends for forex
            $currentFrom = $this->skipWeekend($currentFrom);
            if ($currentFrom->gte($to)) {
                break;
            }

            $candles = $this->requestCandles(
                $baseUrl, $instrument, $granularity,
                $currentFrom, $to, $token, $timeframe
            );

            if ($candles->isEmpty()) {
                break;
            }

            $allCandles = $allCandles->merge($candles);

            // Advance past the last returned candle
            $lastTimestamp = $candles->last()['timestamp'] ?? null;
            if ($lastTimestamp) {
                $currentFrom = Carbon::parse($lastTimestamp)->addSecond();
            } else {
                break;
            }

            // If we got fewer than max, we've reached the end
            if ($candles->count() < self::MAX_BARS_PER_REQUEST) {
                break;
            }
        }

        Log::info("OANDA: fetched {$allCandles->count()} {$timeframe} candles for {$symbol}");

        return $allCandles;
    }

    public function supportsRealtime(): bool
    {
        return true; // Pricing stream API
    }

    public function getName(): string
    {
        return 'oanda';
    }

    private function requestCandles(
        string $baseUrl, string $instrument, string $granularity,
        Carbon $from, Carbon $to, string $token, string $timeframe
    ): Collection {
        $url = "{$baseUrl}/v3/instruments/{$instrument}/candles";

        $retries = 0;
        while ($retries < self::MAX_RETRIES) {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$token}",
                    'Accept-Datetime-Format' => 'RFC3339',
                ])
                ->get($url, [
                    'granularity' => $granularity,
                    'from' => $from->toIso8601ZuluString(),
                    'to' => $to->toIso8601ZuluString(),
                    'count' => self::MAX_BARS_PER_REQUEST,
                    'price' => 'MBA', // Mid, Bid, Ask
                ]);

            if ($response->successful()) {
                $data = $response->json('candles', []);

                return collect($data)
                    ->filter(fn (array $c) => ($c['complete'] ?? false) === true)
                    ->map(function (array $c) use ($timeframe) {
                        // Use mid prices
                        $mid = $c['mid'] ?? [];

                        return [
                            'timeframe' => $timeframe,
                            'timestamp' => Carbon::parse($c['time'])->toDateTimeString(),
                            'open' => (float) ($mid['o'] ?? 0),
                            'high' => (float) ($mid['h'] ?? 0),
                            'low' => (float) ($mid['l'] ?? 0),
                            'close' => (float) ($mid['c'] ?? 0),
                            'volume' => (float) ($c['volume'] ?? 0),
                        ];
                    })
                    ->values();
            }

            if ($response->status() === 429) {
                $wait = (int) pow(2, $retries + 1);
                Log::warning("OANDA rate limited, retrying in {$wait}s...");
                sleep($wait);
                $retries++;

                continue;
            }

            if ($response->status() === 401) {
                Log::error('OANDA: Bearer token expired or invalid');

                return collect();
            }

            Log::error("OANDA API error: {$response->status()} — {$response->body()}");

            return collect();
        }

        return collect();
    }

    /**
     * Convert symbol format for OANDA.
     * EURUSD → EUR_USD, XAUUSD → XAU_USD, etc.
     */
    private function formatInstrument(string $symbol): string
    {
        // Already formatted
        if (str_contains($symbol, '_')) {
            return $symbol;
        }

        // Common forex pairs (6 chars)
        if (strlen($symbol) === 6 && ctype_alpha($symbol)) {
            return substr($symbol, 0, 3) . '_' . substr($symbol, 3);
        }

        // Commodities: XAUUSD, XAGUSD
        if (str_starts_with($symbol, 'XAU') || str_starts_with($symbol, 'XAG')) {
            return substr($symbol, 0, 3) . '_' . substr($symbol, 3);
        }

        // Indices and others — return as-is (may need manual mapping)
        return $symbol;
    }

    /**
     * Skip weekend hours for forex market.
     */
    private function skipWeekend(Carbon $dt): Carbon
    {
        $dow = $dt->dayOfWeek;

        // Saturday
        if ($dow === 6) {
            return $dt->copy()->next(Carbon::SUNDAY)->setTime(self::WEEKEND_OPEN_HOUR, 0);
        }

        // Sunday before market open
        if ($dow === 0 && $dt->hour < self::WEEKEND_OPEN_HOUR) {
            return $dt->copy()->setTime(self::WEEKEND_OPEN_HOUR, 0);
        }

        // Friday after market close
        if ($dow === 5 && $dt->hour >= self::WEEKEND_CLOSE_HOUR) {
            return $dt->copy()->next(Carbon::SUNDAY)->setTime(self::WEEKEND_OPEN_HOUR, 0);
        }

        return $dt;
    }
}
