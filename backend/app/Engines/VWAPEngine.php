<?php

declare(strict_types=1);

namespace App\Engines;

class VWAPEngine implements EngineInterface
{
    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 20) {
            return new EngineResult(engine: 'vwap', symbol: $symbol, timeframe: $timeframe);
        }

        $vwapData = $this->computeVwap($candles);
        $signals = $this->generateSignals($candles, $vwapData, $timeframe);

        return new EngineResult(
            engine: 'vwap',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: ['vwap' => $vwapData],
        );
    }

    private function generateSignals(array $candles, array $vwapData, string $timeframe): array
    {
        $n = count($candles);
        if ($n < 5) {
            return [];
        }

        $signals = [];
        $last = $n - 1;

        // --- VWAP Reclaim (bullish) ---
        // Price was below VWAP, then closes above it for 2 consecutive candles
        if (
            (float) $candles[$last - 2]['close'] < $vwapData[$last - 2]['vwap']
            && (float) $candles[$last - 1]['close'] > $vwapData[$last - 1]['vwap']
            && (float) $candles[$last]['close'] > $vwapData[$last]['vwap']
        ) {
            $signals[] = [
                'type' => 'vwap_reclaim',
                'direction' => 'buy',
                'timeframe' => $timeframe,
                'timestamp' => $candles[$last]['timestamp'],
                'price' => (float) $candles[$last]['close'],
                'vwap' => $vwapData[$last]['vwap'],
                'confluence_score' => 60,
            ];
        }

        // --- VWAP Rejection (bearish) ---
        // Price was above VWAP, then closes below it for 2 consecutive candles
        if (
            (float) $candles[$last - 2]['close'] > $vwapData[$last - 2]['vwap']
            && (float) $candles[$last - 1]['close'] < $vwapData[$last - 1]['vwap']
            && (float) $candles[$last]['close'] < $vwapData[$last]['vwap']
        ) {
            $signals[] = [
                'type' => 'vwap_rejection',
                'direction' => 'sell',
                'timeframe' => $timeframe,
                'timestamp' => $candles[$last]['timestamp'],
                'price' => (float) $candles[$last]['close'],
                'vwap' => $vwapData[$last]['vwap'],
                'confluence_score' => 60,
            ];
        }

        // --- Upper Band Rejection (bearish) ---
        // Price touched upper2 band and reversed below it
        if (
            (float) $candles[$last - 1]['high'] >= $vwapData[$last - 1]['upper2']
            && (float) $candles[$last]['close'] < $vwapData[$last]['upper2']
        ) {
            $signals[] = [
                'type' => 'vwap_upper_band_rejection',
                'direction' => 'sell',
                'timeframe' => $timeframe,
                'timestamp' => $candles[$last]['timestamp'],
                'price' => (float) $candles[$last]['close'],
                'band' => $vwapData[$last]['upper2'],
                'confluence_score' => 55,
            ];
        }

        // --- Lower Band Rejection (bullish) ---
        // Price touched lower2 band and reversed above it
        if (
            (float) $candles[$last - 1]['low'] <= $vwapData[$last - 1]['lower2']
            && (float) $candles[$last]['close'] > $vwapData[$last]['lower2']
        ) {
            $signals[] = [
                'type' => 'vwap_lower_band_rejection',
                'direction' => 'buy',
                'timeframe' => $timeframe,
                'timestamp' => $candles[$last]['timestamp'],
                'price' => (float) $candles[$last]['close'],
                'band' => $vwapData[$last]['lower2'],
                'confluence_score' => 55,
            ];
        }

        return $signals;
    }

    private function computeVwap(array $candles): array
    {
        $cumTPV = 0;
        $cumVol = 0;
        $result = [];

        foreach ($candles as $i => $c) {
            $tp = ((float) $c['high'] + (float) $c['low'] + (float) $c['close']) / 3;
            $vol = (float) $c['volume'];
            $cumTPV += $tp * $vol;
            $cumVol += $vol;

            $vwap = $cumVol > 0 ? $cumTPV / $cumVol : $tp;

            // Rolling std dev for bands (20-period)
            $start = max(0, $i - 19);
            $slice = array_slice($candles, $start, $i - $start + 1);
            $avg = 0;
            foreach ($slice as $s) {
                $avg += ((float) $s['high'] + (float) $s['low'] + (float) $s['close']) / 3;
            }
            $avg /= count($slice);

            $variance = 0;
            foreach ($slice as $s) {
                $p = ((float) $s['high'] + (float) $s['low'] + (float) $s['close']) / 3;
                $variance += ($p - $avg) ** 2;
            }
            $std = sqrt($variance / count($slice));

            $result[] = [
                'timestamp' => $c['timestamp'],
                'vwap' => round($vwap, 2),
                'upper1' => round($vwap + $std, 2),
                'lower1' => round($vwap - $std, 2),
                'upper2' => round($vwap + $std * 2, 2),
                'lower2' => round($vwap - $std * 2, 2),
            ];
        }

        return $result;
    }
}
