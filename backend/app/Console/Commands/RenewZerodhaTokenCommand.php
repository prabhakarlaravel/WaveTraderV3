<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RenewZerodhaTokenCommand extends Command
{
    protected $signature = 'zerodha:renew-token
        {--request-token= : The request token from Kite login redirect}';

    protected $description = 'Renew Zerodha KiteConnect access token (expires daily at midnight IST)';

    public function handle(): int
    {
        $apiKey = Setting::get('zerodha_api_key');
        $apiSecret = Setting::get('zerodha_api_secret');

        if (! $apiKey || ! $apiSecret) {
            $this->error('Zerodha API Key and Secret must be configured in Settings.');

            return self::FAILURE;
        }

        $requestToken = $this->option('request-token');

        if (! $requestToken) {
            // Show login URL for manual token generation
            $loginUrl = "https://kite.zerodha.com/connect/login?v=3&api_key={$apiKey}";
            $this->info('Zerodha access token renewal requires a request_token.');
            $this->newLine();
            $this->info('Step 1: Visit this URL in your browser:');
            $this->line("  {$loginUrl}");
            $this->newLine();
            $this->info('Step 2: After login, you will be redirected with a request_token parameter.');
            $this->info('Step 3: Run this command again with the token:');
            $this->line("  php artisan zerodha:renew-token --request-token=YOUR_TOKEN");

            return self::SUCCESS;
        }

        $this->info('Exchanging request token for access token...');

        // Generate checksum: SHA-256 of (api_key + request_token + api_secret)
        $checksum = hash('sha256', $apiKey . $requestToken . $apiSecret);

        $response = Http::timeout(30)
            ->asForm()
            ->post('https://api.kite.trade/session/token', [
                'api_key' => $apiKey,
                'request_token' => $requestToken,
                'checksum' => $checksum,
            ]);

        if (! $response->successful()) {
            $this->error("Token exchange failed: {$response->status()} — {$response->body()}");
            Log::error("Zerodha token renewal failed: {$response->body()}");

            // Notify via Telegram if configured
            $this->notifyFailure("Zerodha token renewal failed: {$response->status()}");

            return self::FAILURE;
        }

        $data = $response->json('data', []);
        $accessToken = $data['access_token'] ?? null;

        if (! $accessToken) {
            $this->error('No access_token in response.');

            return self::FAILURE;
        }

        // Store the new access token (encrypted)
        Setting::set('zerodha_access_token', $accessToken, 'exchange', true);

        // Store additional user info
        if (isset($data['user_id'])) {
            Setting::set('zerodha_user_id', $data['user_id'], 'exchange');
        }
        if (isset($data['user_name'])) {
            Setting::set('zerodha_user_name', $data['user_name'], 'exchange');
        }

        $userName = $data['user_name'] ?? 'unknown';
        $this->info("Access token renewed successfully for user: {$userName}");
        $this->info('Token will expire at midnight IST tonight.');

        Log::info("Zerodha access token renewed for {$userName}");

        return self::SUCCESS;
    }

    private function notifyFailure(string $message): void
    {
        $webhookUrl = Setting::get('telegram_webhook');
        if (! $webhookUrl) {
            return;
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'text' => "⚠️ WaveTrader Alert: {$message}",
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to send Telegram notification: {$e->getMessage()}");
        }
    }
}
