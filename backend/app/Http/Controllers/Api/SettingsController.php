<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    // ── Zerodha token flow ───────────────────────────────────────────────────

    public function zerodhaLoginUrl(): JsonResponse
    {
        $apiKey = Setting::get('zerodha_api_key');

        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'Zerodha API Key not yet configured. Save it first.']);
        }

        $loginUrl = "https://kite.zerodha.com/connect/login?api_key={$apiKey}&v=3";

        return response()->json(['success' => true, 'url' => $loginUrl]);
    }

    public function zerodhaExchangeToken(Request $request): JsonResponse
    {
        $request->validate(['request_token' => 'required|string']);

        $apiKey    = Setting::get('zerodha_api_key');
        $apiSecret = Setting::get('zerodha_api_secret');

        if (! $apiKey || ! $apiSecret) {
            return response()->json(['success' => false, 'message' => 'API Key and Secret must be saved first.']);
        }

        // KiteConnect checksum = SHA-256(api_key + request_token + api_secret)
        $checksum = hash('sha256', $apiKey.$request->request_token.$apiSecret);

        $response = Http::asForm()
            ->withHeaders(['X-Kite-Version' => '3'])
            ->post('https://api.kite.trade/session/token', [
                'api_key'       => $apiKey,
                'request_token' => $request->request_token,
                'checksum'      => $checksum,
            ]);

        if ($response->successful()) {
            $accessToken = $response->json('data.access_token');
            $userName    = $response->json('data.user_name', '');

            if ($accessToken) {
                Setting::set('zerodha_access_token', $accessToken, 'exchange', true);

                return response()->json([
                    'success'   => true,
                    'message'   => "Token generated for {$userName}",
                    'user_name' => $userName,
                ]);
            }
        }

        $errMsg = $response->json('message') ?? $response->body();

        return response()->json(['success' => false, 'message' => "Token exchange failed: {$errMsg}"]);
    }

    public function zerodhaBalance(): JsonResponse
    {
        $apiKey      = Setting::get('zerodha_api_key');
        $accessToken = Setting::get('zerodha_access_token');

        if (! $apiKey || ! $accessToken) {
            return response()->json(['success' => false, 'has_token' => false, 'message' => 'No active session. Generate token first.']);
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'X-Kite-Version' => '3',
                'Authorization'  => "token {$apiKey}:{$accessToken}",
            ])
            ->get('https://api.kite.trade/user/margins');

        if ($response->successful()) {
            $data      = $response->json('data', []);
            $equity    = $data['equity'] ?? [];
            $commodity = $data['commodity'] ?? [];

            return response()->json([
                'success'   => true,
                'has_token' => true,
                'equity'    => [
                    'available' => number_format((float) ($equity['available']['live_balance'] ?? $equity['net'] ?? 0), 2),
                    'used'      => number_format((float) ($equity['utilised']['debits'] ?? 0), 2),
                ],
                'commodity' => [
                    'available' => number_format((float) ($commodity['available']['live_balance'] ?? $commodity['net'] ?? 0), 2),
                    'used'      => number_format((float) ($commodity['utilised']['debits'] ?? 0), 2),
                ],
            ]);
        }

        // Token might be expired
        if ($response->status() === 403) {
            return response()->json(['success' => false, 'has_token' => true, 'expired' => true, 'message' => 'Session expired. Please regenerate token.']);
        }

        return response()->json(['success' => false, 'has_token' => true, 'message' => 'Balance fetch failed: '.$response->status()]);
    }

    /**
     * Browser-facing OAuth callback — Zerodha redirects here after login.
     * Configured redirect URL in Kite developer console: http://localhost:8000/zerodha/callback
     *
     * URL format:  /zerodha/callback?action=login&type=login&status=success&request_token=xxx
     */
    public function zerodhaCallback(Request $request): RedirectResponse
    {
        $frontendUrl  = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $settingsPage = "{$frontendUrl}/settings";

        $status       = $request->query('status');
        $requestToken = $request->query('request_token');

        if ($status !== 'success' || ! $requestToken) {
            $msg = urlencode('Zerodha login was cancelled or failed. Please try again.');

            return redirect("{$settingsPage}?zerodha_status=error&message={$msg}");
        }

        $apiKey    = Setting::get('zerodha_api_key');
        $apiSecret = Setting::get('zerodha_api_secret');

        if (! $apiKey || ! $apiSecret) {
            $msg = urlencode('Save API Key and Secret before generating a token.');

            return redirect("{$settingsPage}?zerodha_status=error&message={$msg}");
        }

        // KiteConnect checksum = SHA-256(api_key + request_token + api_secret)
        $checksum = hash('sha256', $apiKey.$requestToken.$apiSecret);

        $response = Http::timeout(15)
            ->asForm()
            ->withHeaders(['X-Kite-Version' => '3'])
            ->post('https://api.kite.trade/session/token', [
                'api_key'       => $apiKey,
                'request_token' => $requestToken,
                'checksum'      => $checksum,
            ]);

        if ($response->successful()) {
            $accessToken = $response->json('data.access_token');
            $userName    = $response->json('data.user_name', '');

            if ($accessToken) {
                Setting::set('zerodha_access_token', $accessToken, 'exchange', true);
                Log::info('Zerodha access token refreshed', ['user' => $userName]);

                $nameEnc = urlencode($userName);

                return redirect("{$settingsPage}?zerodha_status=success&user_name={$nameEnc}");
            }
        }

        $errMsg = urlencode($response->json('message') ?? 'Token exchange failed ('.$response->status().')');

        return redirect("{$settingsPage}?zerodha_status=error&message={$errMsg}");
    }

    // ── Private test helpers ─────────────────────────────────────────────────

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
