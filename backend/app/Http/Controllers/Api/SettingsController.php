<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = Setting::all()->groupBy('group');

        return response()->json($settings);
    }

    public function group(string $group): JsonResponse
    {
        return response()->json(Setting::where('group', $group)->get());
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable|string',
            'settings.*.group' => 'nullable|string',
            'settings.*.encrypted' => 'nullable|boolean',
        ]);

        foreach ($request->settings as $item) {
            Setting::set(
                $item['key'],
                $item['value'] ?? null,
                $item['group'] ?? 'general',
                $item['encrypted'] ?? false,
            );
        }

        return response()->json(['message' => 'Settings updated']);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $request->validate(['exchange' => 'required|string|in:binance,zerodha,oanda']);

        $exchange = $request->exchange;

        try {
            return match ($exchange) {
                'binance' => $this->testBinance(),
                'zerodha' => $this->testZerodha(),
                'oanda' => $this->testOanda(),
            };
        } catch (\Throwable $e) {
            Log::error("Connection test failed for {$exchange}: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function testBinance(): JsonResponse
    {
        $apiKey = Setting::get('binance_api_key');
        $response = Http::timeout(10)
            ->withHeaders($apiKey ? ['X-MBX-APIKEY' => $apiKey] : [])
            ->get('https://api.binance.com/api/v3/ping');

        if ($response->successful()) {
            return response()->json([
                'success' => true,
                'message' => 'Binance API connected successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Binance API returned status '.$response->status(),
        ]);
    }

    private function testZerodha(): JsonResponse
    {
        $apiKey = Setting::get('zerodha_api_key');
        $accessToken = Setting::get('zerodha_access_token');

        if (! $apiKey || ! $accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'API Key and Access Token required',
            ]);
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'X-Kite-Version' => '3',
                'Authorization' => "token {$apiKey}:{$accessToken}",
            ])
            ->get('https://api.kite.trade/user/profile');

        return response()->json([
            'success' => $response->successful(),
            'message' => $response->successful() ? 'Zerodha connected' : 'Zerodha auth failed: '.$response->status(),
        ]);
    }

    private function testOanda(): JsonResponse
    {
        $accountId = Setting::get('oanda_account_id');
        $token = Setting::get('oanda_bearer_token');
        $mode = Setting::get('oanda_mode', 'practice');

        if (! $accountId || ! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Account ID and Bearer Token required',
            ]);
        }

        $baseUrl = $mode === 'live'
            ? 'https://api-fxtrade.oanda.com'
            : 'https://api-fxpractice.oanda.com';

        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get("{$baseUrl}/v3/accounts/{$accountId}/summary");

        return response()->json([
            'success' => $response->successful(),
            'message' => $response->successful() ? 'OANDA connected' : 'OANDA auth failed: '.$response->status(),
        ]);
    }
}
