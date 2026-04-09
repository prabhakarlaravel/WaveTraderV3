<?php

declare(strict_types=1);

namespace App\Engines;

class SMCEngine implements EngineInterface
{
    private const SWING_MAP = [
        '1M' => 3, '5M' => 4, '15M' => 5, '1H' => 8, '4H' => 12, '1D' => 20,
    ];

    private int $swingStrength;

    public function __construct(int $swingStrength = 5)
    {
        $this->swingStrength = $swingStrength;
    }

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 50) {
            return new EngineResult(engine: 'smc', symbol: $symbol, timeframe: $timeframe);
        }

        $strength = self::SWING_MAP[$timeframe] ?? $this->swingStrength;
        $swings = $this->detectSwings($candles, $strength);

        // Premium / Equilibrium / Discount zones
        $zones = $this->calculatePremiumDiscount($swings);

        // Liquidity pools (BSL / SSL)
        $liquidityPools = $this->detectLiquidityPools($swings, $candles);

        // Inducement detection
        $inducements = $this->detectInducements($swings, $candles);

        // OTE zone (Optimal Trade Entry: 0.618-0.786 Fibonacci)
        $oteZones = $this->calculateOTE($swings);

        // ICT Power of 3 (Accumulation, Manipulation, Distribution)
        $po3 = $this->detectPowerOf3($candles);

        // Generate signals
        $signals = $this->generateSignals($zones, $liquidityPools, $oteZones, $candles, $timeframe);

        return new EngineResult(
            engine: 'smc',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: [
                'premiumDiscount' => $zones,
                'liquidityPools' => $liquidityPools,
                'inducements' => $inducements,
                'oteZones' => $oteZones,
                'powerOf3' => $po3,
            ],
            metadata: [
                'swing_count' => count($swings),
                'pool_count' => count($liquidityPools),
                'inducement_count' => count($inducements),
                'current_zone' => $zones['currentZone'] ?? 'equilibrium',
            ],
        );
    }

    private function detectSwings(array $candles, int $strength): array
    {
        $swings = [];
        $n = $strength;

        for ($i = $n; $i < count($candles) - $n; $i++) {
            $isHigh = true;
            $isLow = true;
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];

            for ($j = 1; $j <= $n; $j++) {
                if ((float) $candles[$i - $j]['high'] >= $high || (float) $candles[$i + $j]['high'] >= $high) {
                    $isHigh = false;
                }
                if ((float) $candles[$i - $j]['low'] <= $low || (float) $candles[$i + $j]['low'] <= $low) {
                    $isLow = false;
                }
            }

            if ($isHigh) {
                $swings[] = ['type' => 'high', 'price' => $high, 'timestamp' => $candles[$i]['timestamp'], 'index' => $i];
            }
            if ($isLow) {
                $swings[] = ['type' => 'low', 'price' => $low, 'timestamp' => $candles[$i]['timestamp'], 'index' => $i];
            }
        }

        usort($swings, fn ($a, $b) => $a['index'] <=> $b['index']);

        return $swings;
    }

    /**
     * Premium (above equilibrium) / Discount (below equilibrium) zones.
     * Based on the most recent significant swing range.
     */
    private function calculatePremiumDiscount(array $swings): array
    {
        if (count($swings) < 4) {
            return [];
        }

        // Find the most recent significant high and low
        $recentHighs = array_filter($swings, fn ($s) => $s['type'] === 'high');
        $recentLows = array_filter($swings, fn ($s) => $s['type'] === 'low');

        if (empty($recentHighs) || empty($recentLows)) {
            return [];
        }

        $highSwing = end($recentHighs);
        $lowSwing = end($recentLows);

        // Use the highest high and lowest low from last N swings
        $lastN = array_slice($swings, -10);
        $rangeHigh = max(array_column($lastN, 'price'));
        $rangeLow = min(array_column($lastN, 'price'));

        $range = $rangeHigh - $rangeLow;
        if ($range <= 0) {
            return [];
        }

        $equilibrium = $rangeLow + $range * 0.5;
        $premiumStart = $rangeLow + $range * 0.5;
        $discountEnd = $rangeLow + $range * 0.5;

        return [
            'rangeHigh' => round($rangeHigh, 2),
            'rangeLow' => round($rangeLow, 2),
            'equilibrium' => round($equilibrium, 2),
            'premium' => ['high' => round($rangeHigh, 2), 'low' => round($premiumStart, 2)],
            'discount' => ['high' => round($discountEnd, 2), 'low' => round($rangeLow, 2)],
            'currentZone' => 'equilibrium', // Updated below by signal generation
            'highTimestamp' => $highSwing['timestamp'],
            'lowTimestamp' => $lowSwing['timestamp'],
        ];
    }

    /**
     * Detect buy-side liquidity (BSL) and sell-side liquidity (SSL).
     * BSL: clusters of equal highs (resting buy stops above).
     * SSL: clusters of equal lows (resting sell stops below).
     */
    private function detectLiquidityPools(array $swings, array $candles): array
    {
        $pools = [];
        $tolerance = $this->calculateATR($candles, 14) * 0.3;

        // Find equal highs (BSL)
        $highs = array_values(array_filter($swings, fn ($s) => $s['type'] === 'high'));
        for ($i = 0; $i < count($highs); $i++) {
            for ($j = $i + 1; $j < count($highs); $j++) {
                if (abs($highs[$j]['index'] - $highs[$i]['index']) < 10) {
                    continue;
                }
                if (abs($highs[$i]['price'] - $highs[$j]['price']) < $tolerance) {
                    // Check for 3+ swing cluster at same price level
                    $clusterCount = 2;
                    for ($k = $j + 1; $k < count($highs); $k++) {
                        if (abs($highs[$k]['price'] - $highs[$i]['price']) < $tolerance
                            && abs($highs[$k]['index'] - $highs[$i]['index']) >= 10) {
                            $clusterCount++;
                        }
                    }
                    $pools[] = [
                        'type' => 'BSL',
                        'price' => round(max($highs[$i]['price'], $highs[$j]['price']), 2),
                        'timestamp' => $highs[$j]['timestamp'],
                        'strength' => $clusterCount >= 3 ? 3 : 2,
                        'swept' => $this->isSwept($highs[$j]['price'], $highs[$j]['index'], $candles, 'high'),
                    ];
                    break;
                }
            }
        }

        // Find equal lows (SSL)
        $lows = array_values(array_filter($swings, fn ($s) => $s['type'] === 'low'));
        for ($i = 0; $i < count($lows); $i++) {
            for ($j = $i + 1; $j < count($lows); $j++) {
                if (abs($lows[$j]['index'] - $lows[$i]['index']) < 10) {
                    continue;
                }
                if (abs($lows[$i]['price'] - $lows[$j]['price']) < $tolerance) {
                    // Check for 3+ swing cluster at same price level
                    $clusterCount = 2;
                    for ($k = $j + 1; $k < count($lows); $k++) {
                        if (abs($lows[$k]['price'] - $lows[$i]['price']) < $tolerance
                            && abs($lows[$k]['index'] - $lows[$i]['index']) >= 10) {
                            $clusterCount++;
                        }
                    }
                    $pools[] = [
                        'type' => 'SSL',
                        'price' => round(min($lows[$i]['price'], $lows[$j]['price']), 2),
                        'timestamp' => $lows[$j]['timestamp'],
                        'strength' => $clusterCount >= 3 ? 3 : 2,
                        'swept' => $this->isSwept($lows[$j]['price'], $lows[$j]['index'], $candles, 'low'),
                    ];
                    break;
                }
            }
        }

        return array_slice($pools, -8);
    }

    /**
     * Detect inducement: a false BOS that lures traders before reversal.
     * When price takes out a minor swing but fails to hold and reverses.
     */
    private function detectInducements(array $swings, array $candles): array
    {
        $inducements = [];

        for ($i = 2; $i < count($swings); $i++) {
            $curr = $swings[$i];
            $prev = $swings[$i - 2]; // Same type swing 2 back

            if ($curr['type'] !== $prev['type']) {
                continue;
            }

            // Check if price barely broke the level then reversed
            if ($curr['type'] === 'high') {
                $breakAmount = $curr['price'] - $prev['price'];
                $atr = $this->calculateATR($candles, 14);
                if ($breakAmount > 0 && $breakAmount < $atr * 0.5) {
                    // Marginal break = inducement
                    $inducements[] = [
                        'type' => 'bullish_inducement',
                        'price' => round($prev['price'], 2),
                        'break_price' => round($curr['price'], 2),
                        'timestamp' => $curr['timestamp'],
                    ];
                }
            }
            if ($curr['type'] === 'low') {
                $breakAmount = $prev['price'] - $curr['price'];
                $atr = $this->calculateATR($candles, 14);
                if ($breakAmount > 0 && $breakAmount < $atr * 0.5) {
                    $inducements[] = [
                        'type' => 'bearish_inducement',
                        'price' => round($prev['price'], 2),
                        'break_price' => round($curr['price'], 2),
                        'timestamp' => $curr['timestamp'],
                    ];
                }
            }
        }

        return array_slice($inducements, -6);
    }

    /**
     * Calculate OTE (Optimal Trade Entry) zones: 0.618-0.786 Fibonacci retracement
     * of the most recent impulse move.
     */
    private function calculateOTE(array $swings): array
    {
        $oteZones = [];

        // Need at least 3 swings (impulse start → impulse end → retracement)
        for ($i = 2; $i < count($swings); $i++) {
            $impulseStart = $swings[$i - 2];
            $impulseEnd = $swings[$i - 1];
            $retracement = $swings[$i];

            // Bullish OTE: low → high → retracement into 0.618-0.786
            if ($impulseStart['type'] === 'low' && $impulseEnd['type'] === 'high') {
                $move = $impulseEnd['price'] - $impulseStart['price'];
                if ($move <= 0) {
                    continue;
                }

                $oteHigh = $impulseEnd['price'] - $move * 0.618;
                $oteLow = $impulseEnd['price'] - $move * 0.786;

                $oteZones[] = [
                    'type' => 'bullish',
                    'high' => round($oteHigh, 2),
                    'low' => round($oteLow, 2),
                    'impulse_start' => $impulseStart['price'],
                    'impulse_end' => $impulseEnd['price'],
                    'timestamp' => $impulseEnd['timestamp'],
                ];
            }

            // Bearish OTE: high → low → retracement into 0.618-0.786
            if ($impulseStart['type'] === 'high' && $impulseEnd['type'] === 'low') {
                $move = $impulseStart['price'] - $impulseEnd['price'];
                if ($move <= 0) {
                    continue;
                }

                $oteLow = $impulseEnd['price'] + $move * 0.618;
                $oteHigh = $impulseEnd['price'] + $move * 0.786;

                $oteZones[] = [
                    'type' => 'bearish',
                    'high' => round($oteHigh, 2),
                    'low' => round($oteLow, 2),
                    'impulse_start' => $impulseStart['price'],
                    'impulse_end' => $impulseEnd['price'],
                    'timestamp' => $impulseEnd['timestamp'],
                ];
            }
        }

        return array_slice($oteZones, -4);
    }

    /**
     * ICT Power of 3: Accumulation, Manipulation, Distribution.
     * Simplified: detect session-based patterns in the last 3 significant moves.
     */
    private function detectPowerOf3(array $candles): array
    {
        if (count($candles) < 30) {
            return [];
        }

        $recent = array_slice($candles, -30);
        $phases = [];

        // Split into 3 segments
        $segLen = 10;
        for ($seg = 0; $seg < 3; $seg++) {
            $start = $seg * $segLen;
            $slice = array_slice($recent, $start, $segLen);
            if (empty($slice)) {
                continue;
            }

            $open = (float) $slice[0]['open'];
            $close = (float) end($slice)['close'];
            $high = max(array_map(fn ($c) => (float) $c['high'], $slice));
            $low = min(array_map(fn ($c) => (float) $c['low'], $slice));
            $range = $high - $low;

            $direction = $close > $open ? 'bull' : 'bear';
            $bodyPct = $range > 0 ? abs($close - $open) / $range * 100 : 0;

            $phaseName = match ($seg) {
                0 => 'Accumulation',
                1 => 'Manipulation',
                2 => 'Distribution',
            };

            $phases[] = [
                'phase' => $phaseName,
                'direction' => $direction,
                'range' => round($range, 2),
                'body_pct' => round($bodyPct, 1),
                'timestamp_start' => $slice[0]['timestamp'],
                'timestamp_end' => end($slice)['timestamp'],
            ];
        }

        return $phases;
    }

    /**
     * Generate SMC trade signals from zone analysis.
     */
    private function generateSignals(array $zones, array $pools, array $oteZones, array $candles, string $timeframe): array
    {
        $signals = [];
        if (empty($candles) || empty($zones)) {
            return $signals;
        }

        $lastCandle = end($candles);
        $currentPrice = (float) $lastCandle['close'];

        // Determine current zone
        $equilibrium = $zones['equilibrium'] ?? 0;
        if ($equilibrium > 0) {
            if ($currentPrice > $equilibrium) {
                $zones['currentZone'] = 'premium';
            } elseif ($currentPrice < $equilibrium) {
                $zones['currentZone'] = 'discount';
            }
        }

        // Signal: price in discount zone near OTE = buy opportunity
        foreach ($oteZones as $ote) {
            if ($ote['type'] === 'bullish' && $currentPrice >= $ote['low'] && $currentPrice <= $ote['high']) {
                $signals[] = [
                    'timeframe' => $timeframe,
                    'engine' => 'smc',
                    'direction' => 'buy',
                    'entry' => $currentPrice,
                    'sl' => $ote['low'] - ($ote['high'] - $ote['low']),
                    'tp' => $ote['impulse_end'],
                    'confluence_score' => 80,
                    'candle_timestamp' => $lastCandle['timestamp'],
                ];
            }
            if ($ote['type'] === 'bearish' && $currentPrice >= $ote['low'] && $currentPrice <= $ote['high']) {
                $signals[] = [
                    'timeframe' => $timeframe,
                    'engine' => 'smc',
                    'direction' => 'sell',
                    'entry' => $currentPrice,
                    'sl' => $ote['high'] + ($ote['high'] - $ote['low']),
                    'tp' => $ote['impulse_end'],
                    'confluence_score' => 80,
                    'candle_timestamp' => $lastCandle['timestamp'],
                ];
            }
        }

        // Signal: price sweeping liquidity pool = reversal
        foreach ($pools as $pool) {
            if ($pool['swept']) {
                $direction = $pool['type'] === 'BSL' ? 'sell' : 'buy';
                $signals[] = [
                    'timeframe' => $timeframe,
                    'engine' => 'smc',
                    'direction' => $direction,
                    'entry' => $pool['price'],
                    'sl' => null,
                    'tp' => null,
                    'confluence_score' => 70,
                    'candle_timestamp' => $pool['timestamp'],
                ];
            }
        }

        return $signals;
    }

    private function isSwept(float $price, int $fromIndex, array $candles, string $type): bool
    {
        for ($i = $fromIndex + 1; $i < count($candles); $i++) {
            if ($type === 'high' && (float) $candles[$i]['high'] > $price) {
                return true;
            }
            if ($type === 'low' && (float) $candles[$i]['low'] < $price) {
                return true;
            }
        }

        return false;
    }

    private function calculateATR(array $candles, int $period): float
    {
        $trValues = [];
        for ($i = max(1, count($candles) - $period); $i < count($candles); $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $pc = (float) $candles[$i - 1]['close'];
            $trValues[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        return count($trValues) > 0 ? array_sum($trValues) / count($trValues) : 0;
    }
}
