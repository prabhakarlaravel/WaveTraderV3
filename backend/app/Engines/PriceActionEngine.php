<?php

declare(strict_types=1);

namespace App\Engines;

class PriceActionEngine implements EngineInterface
{
    /**
     * Nearby level zones can be injected by RunEnginesJob after OB/FVG engines run.
     * Format: [['price' => float, 'type' => 'ob'|'fvg'|'ote'|'vwap'], ...]
     */
    private array $confluenceLevels = [];

    public function setConfluenceLevels(array $levels): void
    {
        $this->confluenceLevels = $levels;
    }

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 20) {
            return new EngineResult(engine: 'price_action', symbol: $symbol, timeframe: $timeframe);
        }

        $atr = $this->calculateATR($candles, 14);
        if ($atr <= 0) {
            return new EngineResult(engine: 'price_action', symbol: $symbol, timeframe: $timeframe);
        }

        $signals = [];
        $patterns = [];

        // Only scan the last 30 candles for patterns (no need to scan entire history)
        $start = max(1, count($candles) - 30);

        for ($i = $start; $i < count($candles); $i++) {
            $curr = $candles[$i];
            $prev = $candles[$i - 1];

            $detected = $this->detectPatterns($curr, $prev, $atr);

            foreach ($detected as $pattern) {
                $price = (float) $curr['close'];

                // Context filter: score patterns at confluence zones much higher
                $atLevel = $this->isNearConfluenceLevel($price, $atr);
                $atSR = $this->isNearSupportResistance($price, $candles, $i, $atr);

                // Base strength from pattern + ATR-normalized sizing
                $baseStrength = $pattern['strength'];

                // Context multiplier: patterns at levels are meaningful, others are noise
                if ($atLevel) {
                    $baseStrength = min(90, $baseStrength + 20);
                } elseif ($atSR) {
                    $baseStrength = min(85, $baseStrength + 10);
                } else {
                    // Pattern NOT at any level — reduce to noise level
                    $baseStrength = max(15, $baseStrength - 25);
                }

                $patterns[] = [
                    'pattern' => $pattern['name'],
                    'direction' => $pattern['direction'],
                    'timestamp' => $curr['timestamp'],
                    'price' => $price,
                    'strength' => $baseStrength,
                    'at_level' => $atLevel,
                    'at_sr' => $atSR,
                ];

                // Only emit signals for patterns with sufficient strength (at a level)
                if ($baseStrength >= 40) {
                    $signals[] = [
                        'timeframe' => '',
                        'engine' => 'price_action',
                        'direction' => $pattern['direction'],
                        'entry' => $price,
                        'sl' => $pattern['direction'] === 'buy'
                            ? (float) $curr['low']
                            : (float) $curr['high'],
                        'tp' => null,
                        'confluence_score' => $baseStrength,
                        'candle_timestamp' => $curr['timestamp'],
                    ];
                }
            }
        }

        return new EngineResult(
            engine: 'price_action',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: ['patterns' => $patterns],
            metadata: [
                'pattern_count' => count($patterns),
                'at_level_count' => count(array_filter($patterns, fn ($p) => $p['at_level'] || $p['at_sr'])),
            ],
        );
    }

    /**
     * Detect candlestick patterns with ATR-normalized strength.
     */
    private function detectPatterns(array $curr, array $prev, float $atr): array
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

        // ATR-based sizing: scale strength by how large the candle is relative to ATR
        $sizeMultiplier = $atr > 0 ? min(1.5, max(0.5, $cRange / $atr)) : 1.0;

        // Doji — very small body relative to range
        if ($bodyRatio < 0.1 && $cRange > 0) {
            $patterns[] = [
                'name' => 'doji',
                'direction' => 'neutral',
                'strength' => (int) round(25 * $sizeMultiplier),
            ];
        }

        // Hammer — small body at top, long lower wick (bullish reversal)
        if ($lowerWick > $cBody * 2 && $upperWick < $cBody * 0.5 && $cBody > 0) {
            $patterns[] = [
                'name' => 'hammer',
                'direction' => 'buy',
                'strength' => (int) round(50 * $sizeMultiplier),
            ];
        }

        // Shooting Star — small body at bottom, long upper wick (bearish reversal)
        if ($upperWick > $cBody * 2 && $lowerWick < $cBody * 0.5 && $cBody > 0) {
            $patterns[] = [
                'name' => 'shooting_star',
                'direction' => 'sell',
                'strength' => (int) round(50 * $sizeMultiplier),
            ];
        }

        // Bullish Engulfing — current bullish candle engulfs previous bearish candle
        if ($cClose > $cOpen && $pClose < $pOpen
            && $cOpen <= $pClose && $cClose >= $pOpen
            && $cBody > $pBody) {
            $patterns[] = [
                'name' => 'bullish_engulfing',
                'direction' => 'buy',
                'strength' => (int) round(65 * $sizeMultiplier),
            ];
        }

        // Bearish Engulfing — current bearish candle engulfs previous bullish candle
        if ($cClose < $cOpen && $pClose > $pOpen
            && $cOpen >= $pClose && $cClose <= $pOpen
            && $cBody > $pBody) {
            $patterns[] = [
                'name' => 'bearish_engulfing',
                'direction' => 'sell',
                'strength' => (int) round(65 * $sizeMultiplier),
            ];
        }

        // Pinbar — long wick rejection (>66% of range is wick on one side)
        if ($lowerWick > $cRange * 0.66 && $cBody > 0) {
            $patterns[] = [
                'name' => 'bullish_pinbar',
                'direction' => 'buy',
                'strength' => (int) round(60 * $sizeMultiplier),
            ];
        }
        if ($upperWick > $cRange * 0.66 && $cBody > 0) {
            $patterns[] = [
                'name' => 'bearish_pinbar',
                'direction' => 'sell',
                'strength' => (int) round(60 * $sizeMultiplier),
            ];
        }

        return $patterns;
    }

    /**
     * Check if price is near any injected confluence level (OB, FVG, OTE, VWAP).
     */
    private function isNearConfluenceLevel(float $price, float $atr): bool
    {
        $tolerance = $atr * 0.5; // Within 0.5 ATR of a level

        foreach ($this->confluenceLevels as $level) {
            if (abs($price - $level['price']) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if price is near auto-detected support/resistance.
     * Uses recent swing highs/lows as S/R levels.
     */
    private function isNearSupportResistance(float $price, array $candles, int $currentIdx, float $atr): bool
    {
        $tolerance = $atr * 0.5;
        $lookback = min(60, $currentIdx);

        // Collect recent swing highs/lows (simple 3-bar pivot)
        for ($i = max(2, $currentIdx - $lookback); $i < $currentIdx - 1; $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $prevH = (float) $candles[$i - 1]['high'];
            $nextH = (float) $candles[$i + 1]['high'];
            $prevL = (float) $candles[$i - 1]['low'];
            $nextL = (float) $candles[$i + 1]['low'];

            // Swing high
            if ($h > $prevH && $h > $nextH && abs($price - $h) <= $tolerance) {
                return true;
            }
            // Swing low
            if ($l < $prevL && $l < $nextL && abs($price - $l) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    private function calculateATR(array $candles, int $period): float
    {
        $trValues = [];
        $start = max(1, count($candles) - $period);

        for ($i = $start; $i < count($candles); $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $pc = (float) $candles[$i - 1]['close'];
            $trValues[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        return count($trValues) > 0 ? array_sum($trValues) / count($trValues) : 0;
    }
}
