<?php

declare(strict_types=1);

namespace App\Engines;

class PriceActionEngine implements EngineInterface
{
    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 3) {
            return new EngineResult(engine: 'price_action', symbol: $symbol, timeframe: $timeframe);
        }

        $signals = [];
        $patterns = [];

        for ($i = 1; $i < count($candles); $i++) {
            $curr = $candles[$i];
            $prev = $candles[$i - 1];

            $detected = $this->detectPatterns($curr, $prev);

            foreach ($detected as $pattern) {
                $patterns[] = [
                    'pattern' => $pattern['name'],
                    'direction' => $pattern['direction'],
                    'timestamp' => $curr['timestamp'],
                    'price' => (float) $curr['close'],
                ];

                $signals[] = [
                    'timeframe' => '',
                    'engine' => 'price_action',
                    'direction' => $pattern['direction'],
                    'entry' => (float) $curr['close'],
                    'sl' => $pattern['direction'] === 'buy'
                        ? (float) $curr['low']
                        : (float) $curr['high'],
                    'tp' => null,
                    'confluence_score' => $pattern['strength'],
                    'candle_timestamp' => $curr['timestamp'],
                ];
            }
        }

        return new EngineResult(
            engine: 'price_action',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: ['patterns' => $patterns],
            metadata: ['pattern_count' => count($patterns)],
        );
    }

    private function detectPatterns(array $curr, array $prev): array
    {
        $patterns = [];

        $cOpen = (float) $curr['open'];
        $cClose = (float) $curr['close'];
        $cHigh = (float) $curr['high'];
        $cLow = (float) $curr['low'];
        $cBody = abs($cClose - $cOpen);
        $cRange = $cHigh - $cLow;

        $pOpen = (float) $prev['open'];
        $pClose = (float) $prev['close'];
        $pBody = abs($pClose - $pOpen);

        if ($cRange == 0) {
            return $patterns;
        }

        $bodyRatio = $cBody / $cRange;
        $upperWick = $cHigh - max($cOpen, $cClose);
        $lowerWick = min($cOpen, $cClose) - $cLow;

        // Doji — very small body
        if ($bodyRatio < 0.1 && $cRange > 0) {
            $patterns[] = [
                'name' => 'doji',
                'direction' => 'neutral',
                'strength' => 30,
            ];
        }

        // Hammer — small body at top, long lower wick (bullish reversal)
        if ($lowerWick > $cBody * 2 && $upperWick < $cBody * 0.5 && $cBody > 0) {
            $patterns[] = [
                'name' => 'hammer',
                'direction' => 'buy',
                'strength' => 55,
            ];
        }

        // Shooting Star — small body at bottom, long upper wick (bearish reversal)
        if ($upperWick > $cBody * 2 && $lowerWick < $cBody * 0.5 && $cBody > 0) {
            $patterns[] = [
                'name' => 'shooting_star',
                'direction' => 'sell',
                'strength' => 55,
            ];
        }

        // Bullish Engulfing — current bullish candle engulfs previous bearish candle
        if ($cClose > $cOpen && $pClose < $pOpen
            && $cOpen <= $pClose && $cClose >= $pOpen
            && $cBody > $pBody) {
            $patterns[] = [
                'name' => 'bullish_engulfing',
                'direction' => 'buy',
                'strength' => 70,
            ];
        }

        // Bearish Engulfing — current bearish candle engulfs previous bullish candle
        if ($cClose < $cOpen && $pClose > $pOpen
            && $cOpen >= $pClose && $cClose <= $pOpen
            && $cBody > $pBody) {
            $patterns[] = [
                'name' => 'bearish_engulfing',
                'direction' => 'sell',
                'strength' => 70,
            ];
        }

        // Pinbar — long wick rejection (>66% of range is wick on one side)
        if ($lowerWick > $cRange * 0.66 && $cBody > 0) {
            $patterns[] = [
                'name' => 'bullish_pinbar',
                'direction' => 'buy',
                'strength' => 65,
            ];
        }
        if ($upperWick > $cRange * 0.66 && $cBody > 0) {
            $patterns[] = [
                'name' => 'bearish_pinbar',
                'direction' => 'sell',
                'strength' => 65,
            ];
        }

        return $patterns;
    }
}
