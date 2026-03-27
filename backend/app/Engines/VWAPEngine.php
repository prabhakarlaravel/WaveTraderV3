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

        return new EngineResult(
            engine: 'vwap',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: [],
            overlays: ['vwap' => $vwapData],
        );
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
