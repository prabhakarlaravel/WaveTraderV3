<?php

declare(strict_types=1);

namespace App\Engines;

class FVGEngine implements EngineInterface
{
    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 3) {
            return new EngineResult(engine: 'fvg', symbol: $symbol, timeframe: $timeframe);
        }

        $fvgs = $this->detectFVGs($candles);
        $this->calculateFillPercentage($fvgs, $candles);

        return new EngineResult(
            engine: 'fvg',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: [],
            overlays: ['fvgs' => $fvgs],
            metadata: [
                'total' => count($fvgs),
                'unfilled' => count(array_filter($fvgs, fn ($f) => $f['fill_pct'] < 100)),
            ],
        );
    }

    private function detectFVGs(array $candles): array
    {
        $fvgs = [];

        for ($i = 2; $i < count($candles); $i++) {
            $c0 = $candles[$i - 2]; // first candle
            $c1 = $candles[$i - 1]; // middle candle (imbalance)
            $c2 = $candles[$i];     // third candle

            $c0High = (float) $c0['high'];
            $c0Low = (float) $c0['low'];
            $c2High = (float) $c2['high'];
            $c2Low = (float) $c2['low'];

            // Bullish FVG: gap up — candle 3's low > candle 1's high
            if ($c2Low > $c0High) {
                $fvgs[] = [
                    'type' => 'bullish',
                    'high' => $c2Low,
                    'low' => $c0High,
                    'formed_at' => $c1['timestamp'],
                    'fill_pct' => 0,
                    'timeframe' => '', // filled by caller
                    'index' => $i - 1,
                ];
            }

            // Bearish FVG: gap down — candle 1's low > candle 3's high
            if ($c0Low > $c2High) {
                $fvgs[] = [
                    'type' => 'bearish',
                    'high' => $c0Low,
                    'low' => $c2High,
                    'formed_at' => $c1['timestamp'],
                    'fill_pct' => 0,
                    'timeframe' => '',
                    'index' => $i - 1,
                ];
            }
        }

        return $fvgs;
    }

    private function calculateFillPercentage(array &$fvgs, array $candles): void
    {
        foreach ($fvgs as &$fvg) {
            $gapSize = $fvg['high'] - $fvg['low'];
            if ($gapSize <= 0) {
                $fvg['fill_pct'] = 100;
                continue;
            }

            $maxFill = 0;

            // Check candles after the FVG formed
            for ($i = $fvg['index'] + 2; $i < count($candles); $i++) {
                $high = (float) $candles[$i]['high'];
                $low = (float) $candles[$i]['low'];

                if ($fvg['type'] === 'bullish') {
                    // Price needs to drop into the gap
                    if ($low < $fvg['high']) {
                        $fill = min($gapSize, $fvg['high'] - max($low, $fvg['low']));
                        $maxFill = max($maxFill, $fill);
                    }
                } else {
                    // Price needs to rise into the gap
                    if ($high > $fvg['low']) {
                        $fill = min($gapSize, min($high, $fvg['high']) - $fvg['low']);
                        $maxFill = max($maxFill, $fill);
                    }
                }
            }

            $fvg['fill_pct'] = round(($maxFill / $gapSize) * 100, 2);
        }
    }
}
