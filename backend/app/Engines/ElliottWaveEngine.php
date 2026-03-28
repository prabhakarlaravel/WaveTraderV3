<?php

declare(strict_types=1);

namespace App\Engines;

class ElliottWaveEngine implements EngineInterface
{
    private const DEGREES = [
        '1D' => 'Primary',
        '4H' => 'Intermediate',
        '1H' => 'Minor',
        '15M' => 'Minute',
        '5M' => 'Minuette',
        '1M' => 'Sub-Minuette',
    ];

    private const FIB_LEVELS = [0.236, 0.382, 0.5, 0.618, 0.786, 1.0, 1.272, 1.618, 2.618];

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 50) {
            return new EngineResult(engine: 'elliott_wave', symbol: $symbol, timeframe: $timeframe);
        }

        // Step 1: Detect pivots at multiple strengths
        $pivots = $this->detectPivots($candles, 8);

        // Step 2: Build alternating swing sequence
        $swings = $this->buildSwingSequence($pivots);

        if (count($swings) < 5) {
            return new EngineResult(engine: 'elliott_wave', symbol: $symbol, timeframe: $timeframe);
        }

        // Step 3: Label waves (impulse + correction)
        $waveCounts = $this->labelWaves($swings, $candles);

        // Step 4: Validate rules and calculate health
        $validation = $this->validateRules($waveCounts);

        // Step 5: Calculate Fibonacci targets
        $fibTargets = $this->calculateFibTargets($waveCounts);

        // Step 6: Build overlay data
        $degree = self::DEGREES[$timeframe] ?? 'Minor';

        $waveLabels = array_map(function ($w) use ($degree) {
            return [
                'label' => $w['label'],
                'type' => $w['swing_type'],
                'price' => $w['price'],
                'timestamp' => $w['timestamp'],
                'isCorrection' => in_array($w['label'], ['A', 'B', 'C', 'a', 'b', 'c']),
                'degree' => $degree,
                'phase' => $w['phase'],
            ];
        }, $waveCounts);

        // Build signals from wave positions
        $signals = $this->generateSignals($waveCounts, $fibTargets, $timeframe);

        // Step 7: Calculate next wave targets + invalidation
        $nextTargets = $this->calculateNextWaveTargets($waveCounts);

        return new EngineResult(
            engine: 'elliott_wave',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: [
                'waveLabels' => $waveLabels,
                'fibTargets' => $fibTargets,
                'nextTargets' => $nextTargets,
                'waveLine' => array_map(fn ($w) => [
                    'price' => $w['price'],
                    'timestamp' => $w['timestamp'],
                ], $waveCounts),
            ],
            metadata: [
                'degree' => $degree,
                'health_score' => $validation['score'],
                'violations' => $validation['violations'],
                'wave_count' => count($waveCounts),
                'current_wave' => ! empty($waveCounts) ? end($waveCounts)['label'] : null,
                'phase' => ! empty($waveCounts) ? end($waveCounts)['phase'] : null,
            ],
        );
    }

    /**
     * Detect swing pivots using N-bar strength.
     */
    private function detectPivots(array $candles, int $strength): array
    {
        $pivots = [];

        for ($i = $strength; $i < count($candles) - $strength; $i++) {
            $isHigh = true;
            $isLow = true;
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];

            for ($j = 1; $j <= $strength; $j++) {
                if ((float) $candles[$i - $j]['high'] >= $high || (float) $candles[$i + $j]['high'] >= $high) {
                    $isHigh = false;
                }
                if ((float) $candles[$i - $j]['low'] <= $low || (float) $candles[$i + $j]['low'] <= $low) {
                    $isLow = false;
                }
            }

            if ($isHigh) {
                $pivots[] = ['type' => 'high', 'price' => $high, 'timestamp' => $candles[$i]['timestamp'], 'index' => $i];
            }
            if ($isLow) {
                $pivots[] = ['type' => 'low', 'price' => $low, 'timestamp' => $candles[$i]['timestamp'], 'index' => $i];
            }
        }

        usort($pivots, fn ($a, $b) => $a['index'] <=> $b['index']);

        return $pivots;
    }

    /**
     * Build alternating high-low swing sequence, keeping extremes.
     */
    private function buildSwingSequence(array $pivots): array
    {
        if (empty($pivots)) {
            return [];
        }

        $filtered = [$pivots[0]];

        for ($i = 1; $i < count($pivots); $i++) {
            $last = $filtered[count($filtered) - 1];

            if ($pivots[$i]['type'] !== $last['type']) {
                $filtered[] = $pivots[$i];
            } else {
                // Same type — keep the more extreme
                if ($pivots[$i]['type'] === 'high' && $pivots[$i]['price'] > $last['price']) {
                    $filtered[count($filtered) - 1] = $pivots[$i];
                }
                if ($pivots[$i]['type'] === 'low' && $pivots[$i]['price'] < $last['price']) {
                    $filtered[count($filtered) - 1] = $pivots[$i];
                }
            }
        }

        return $filtered;
    }

    /**
     * Label swings as Elliott Wave impulse (1-5) and correction (A-B-C).
     * Detects trend direction and assigns labels accordingly.
     */
    private function labelWaves(array $swings, array $candles): array
    {
        $waves = [];

        // Determine overall trend from first and last swing
        $first = $swings[0];
        $last = $swings[count($swings) - 1];
        $bullishTrend = $last['price'] > $first['price'];

        // Wave sequence: impulse waves trend with direction, corrections against
        $impulseLabels = ['1', '2', '3', '4', '5'];
        $correctionLabels = ['A', 'B', 'C'];
        $fullSequence = array_merge($impulseLabels, $correctionLabels);

        $labelIdx = 0;
        $inCorrection = false;

        for ($i = 0; $i < count($swings) && $labelIdx < count($fullSequence); $i++) {
            $swing = $swings[$i];
            $label = $fullSequence[$labelIdx];
            $isCorrection = in_array($label, $correctionLabels);

            // Determine expected direction for this wave
            $expectUp = $bullishTrend;
            if (in_array($label, ['2', '4', 'B'])) {
                $expectUp = ! $bullishTrend; // Counter-trend waves
            }
            if (in_array($label, ['A', 'C'])) {
                $expectUp = ! $bullishTrend; // Correction goes against main trend
            }

            $waves[] = [
                'label' => $label,
                'swing_type' => $swing['type'],
                'price' => $swing['price'],
                'timestamp' => $swing['timestamp'],
                'index' => $swing['index'],
                'phase' => $isCorrection ? 'CORRECTION' : 'IMPULSE',
            ];

            $labelIdx++;

            // After wave 5, start second cycle if enough swings remain
            if ($labelIdx >= count($fullSequence) && $i + 1 < count($swings)) {
                // Start a new cycle
                $labelIdx = 0;
                $bullishTrend = ! $bullishTrend; // Flip trend for new cycle
            }
        }

        return $waves;
    }

    /**
     * Validate Elliott Wave rules and calculate health score.
     */
    private function validateRules(array $waves): array
    {
        $violations = [];
        $score = 100;

        // Build lookup by label
        $byLabel = [];
        foreach ($waves as $w) {
            $byLabel[$w['label']] = $w;
        }

        // Need at least waves 1-5 for rule checking
        if (! isset($byLabel['1'], $byLabel['2'], $byLabel['3'], $byLabel['4'], $byLabel['5'])) {
            return ['score' => $score, 'violations' => $violations];
        }

        $w1Start = $byLabel['1']['price'];
        $w1End = $byLabel['2']['price'];  // Wave 1 ends where wave 2 starts
        $w2End = $byLabel['2']['price'];
        $w3End = $byLabel['4']['price'];  // Wave 3 ends where wave 4 starts
        $w4End = $byLabel['4']['price'];
        $w5End = $byLabel['5']['price'];

        $isBullish = $byLabel['3']['price'] > $byLabel['1']['price'];

        // Rule 2: Wave 2 cannot retrace beyond the start of Wave 1
        if ($isBullish) {
            if ($w2End < $w1Start) {
                $violations[] = [
                    'rule' => 2,
                    'description' => 'Wave 2 retraced below Wave 1 start',
                    'severity' => 'critical',
                ];
                $score -= 30;
            }
        } else {
            if ($w2End > $w1Start) {
                $violations[] = [
                    'rule' => 2,
                    'description' => 'Wave 2 retraced above Wave 1 start',
                    'severity' => 'critical',
                ];
                $score -= 30;
            }
        }

        // Rule 3: Wave 3 must not be the shortest impulse wave
        $wave1Len = abs($w1End - $w1Start);
        $wave3Len = abs($w3End - $w2End);
        $wave5Len = abs($w5End - $w4End);

        if ($wave3Len < $wave1Len && $wave3Len < $wave5Len) {
            $violations[] = [
                'rule' => 3,
                'description' => 'Wave 3 is the shortest impulse wave',
                'severity' => 'critical',
            ];
            $score -= 25;
        }

        // Rule 4: Wave 4 must not overlap Wave 1 price territory
        if ($isBullish) {
            if ($w4End < $w1End) {
                $violations[] = [
                    'rule' => 4,
                    'description' => 'Wave 4 overlaps Wave 1 price territory',
                    'severity' => 'warning',
                ];
                $score -= 20;
            }
        } else {
            if ($w4End > $w1End) {
                $violations[] = [
                    'rule' => 4,
                    'description' => 'Wave 4 overlaps Wave 1 price territory',
                    'severity' => 'warning',
                ];
                $score -= 20;
            }
        }

        // Bonus: Wave 3 is typically the longest (guideline, not rule)
        if ($wave3Len >= $wave1Len && $wave3Len >= $wave5Len) {
            $score = min(100, $score + 5);
        }

        return ['score' => max(0, $score), 'violations' => $violations];
    }

    /**
     * Calculate Fibonacci extension and retracement targets.
     */
    private function calculateFibTargets(array $waves): array
    {
        $targets = [];
        $byLabel = [];
        foreach ($waves as $w) {
            $byLabel[$w['label']] = $w;
        }

        // Wave 3 target from waves 1 and 2
        if (isset($byLabel['1'], $byLabel['2'])) {
            $w1Start = $byLabel['1']['price'];
            $w1End = $byLabel['2']['price'];
            $w2End = $byLabel['2']['price'];
            $w1Len = abs($w1End - $w1Start);
            $direction = $w1End > $w1Start ? 1 : -1;

            $targets[] = [
                'label' => 'Wave 3 target (1.0)',
                'price' => round($w2End + $w1Len * 1.0 * $direction, 2),
                'fib' => 1.0,
                'wave' => '3',
            ];
            $targets[] = [
                'label' => 'Wave 3 target (1.618)',
                'price' => round($w2End + $w1Len * 1.618 * $direction, 2),
                'fib' => 1.618,
                'wave' => '3',
            ];
            $targets[] = [
                'label' => 'Wave 3 target (2.618)',
                'price' => round($w2End + $w1Len * 2.618 * $direction, 2),
                'fib' => 2.618,
                'wave' => '3',
            ];
        }

        // Wave 5 target from waves 1-3
        if (isset($byLabel['1'], $byLabel['4'])) {
            $w1Start = $byLabel['1']['price'];
            $w3End = isset($byLabel['3']) ? $byLabel['3']['price'] : $byLabel['4']['price'];
            $w4End = $byLabel['4']['price'];
            $w13Len = abs($w3End - $w1Start);
            $direction = $w3End > $w1Start ? 1 : -1;

            $targets[] = [
                'label' => 'Wave 5 target (0.618)',
                'price' => round($w4End + $w13Len * 0.618 * $direction, 2),
                'fib' => 0.618,
                'wave' => '5',
            ];
            $targets[] = [
                'label' => 'Wave 5 target (1.0)',
                'price' => round($w4End + $w13Len * 1.0 * $direction, 2),
                'fib' => 1.0,
                'wave' => '5',
            ];
        }

        // Wave 2 retracement levels
        if (isset($byLabel['1'], $byLabel['2'])) {
            $w1Start = $byLabel['1']['price'];
            $w1End = $byLabel['2']['price'];
            $move = $w1End - $w1Start;

            foreach ([0.382, 0.5, 0.618] as $level) {
                $targets[] = [
                    'label' => "Wave 2 retracement ({$level})",
                    'price' => round($w1End - $move * $level, 2),
                    'fib' => $level,
                    'wave' => '2',
                    'type' => 'retracement',
                ];
            }
        }

        return $targets;
    }

    /**
     * Calculate next wave targets and invalidation based on current wave position.
     * Returns targets for the NEXT expected wave with price levels and colors.
     */
    private function calculateNextWaveTargets(array $waves): array
    {
        if (count($waves) < 2) {
            return ['targets' => [], 'invalidation' => null, 'nextWave' => null, 'retracements' => []];
        }

        $byLabel = [];
        foreach ($waves as $w) {
            $byLabel[$w['label']] = $w;
        }

        $currentWave = end($waves)['label'];
        $targets = [];
        $invalidation = null;
        $nextWave = null;
        $retracements = [];

        // Determine trend direction from wave 1→2
        $isBullish = true;
        if (isset($byLabel['1'], $byLabel['2'])) {
            $isBullish = $byLabel['2']['price'] > $byLabel['1']['price'];
        }
        $dir = $isBullish ? 1 : -1;

        switch ($currentWave) {
            case '1':
                // After W1: expect W2 retracement (50%, 61.8%, 78.6% of W1)
                $nextWave = '2';
                $w1Start = $byLabel['1']['price'];
                $w1End = end($waves)['price']; // W1 end = current position
                $w1Len = abs($w1End - $w1Start);

                foreach ([0.382, 0.5, 0.618, 0.786] as $level) {
                    $price = $w1End - $w1Len * $level * $dir;
                    $type = $level === 0.618 ? 'primary' : ($level === 0.5 ? 'secondary' : 'extended');
                    $color = $type === 'primary' ? '#34d399' : ($type === 'secondary' ? '#8b5cf6' : '#f59e0b');
                    $targets[] = [
                        'label' => "W2 retrace ({$level})",
                        'price' => round($price, 2),
                        'fib' => $level,
                        'type' => $type,
                        'color' => $color,
                    ];
                }
                // Invalidation: W2 must not go beyond W1 start
                $invalidation = ['price' => round($w1Start, 2), 'rule' => 'W2 cannot retrace beyond W1 start'];
                break;

            case '2':
                // After W2: expect W3 extension (1.0, 1.618, 2.618 × W1 from W2 end)
                $nextWave = '3';
                if (isset($byLabel['1'])) {
                    $w1Start = $byLabel['1']['price'];
                    $w1End = $byLabel['2']['price'] ?? end($waves)['price'];
                    $w1Len = abs($w1End - $w1Start);
                    $w2End = end($waves)['price'];

                    $targets[] = ['label' => 'W3 = 1.0 × W1', 'price' => round($w2End + $w1Len * 1.0 * $dir, 2), 'fib' => 1.0, 'type' => 'secondary', 'color' => '#8b5cf6'];
                    $targets[] = ['label' => 'W3 = 1.618 × W1', 'price' => round($w2End + $w1Len * 1.618 * $dir, 2), 'fib' => 1.618, 'type' => 'primary', 'color' => '#34d399'];
                    $targets[] = ['label' => 'W3 = 2.618 × W1', 'price' => round($w2End + $w1Len * 2.618 * $dir, 2), 'fib' => 2.618, 'type' => 'extended', 'color' => '#f59e0b'];

                    $invalidation = ['price' => round($byLabel['1']['price'], 2), 'rule' => 'W3 must not end below W1 start'];
                }
                break;

            case '3':
                // After W3: expect W4 retracement (38.2%, 50% of W3)
                $nextWave = '4';
                if (isset($byLabel['2'])) {
                    $w2End = $byLabel['2']['price'];
                    $w3End = end($waves)['price'];
                    $w3Len = abs($w3End - $w2End);

                    $targets[] = ['label' => 'W4 retrace (0.236)', 'price' => round($w3End - $w3Len * 0.236 * $dir, 2), 'fib' => 0.236, 'type' => 'extended', 'color' => '#f59e0b'];
                    $targets[] = ['label' => 'W4 retrace (0.382)', 'price' => round($w3End - $w3Len * 0.382 * $dir, 2), 'fib' => 0.382, 'type' => 'primary', 'color' => '#34d399'];
                    $targets[] = ['label' => 'W4 retrace (0.5)', 'price' => round($w3End - $w3Len * 0.5 * $dir, 2), 'fib' => 0.5, 'type' => 'secondary', 'color' => '#8b5cf6'];

                    // Invalidation: W4 must not overlap W1 territory
                    if (isset($byLabel['1'])) {
                        $w1End = $byLabel['2']['price']; // W1 end = W2 start
                        $invalidation = ['price' => round($w1End, 2), 'rule' => 'W4 must not overlap W1 territory'];
                    }
                }
                break;

            case '4':
                // After W4: expect W5 extension
                $nextWave = '5';
                if (isset($byLabel['1'], $byLabel['2'])) {
                    $w1Start = $byLabel['1']['price'];
                    $w1End = $byLabel['2']['price'];
                    $w1Len = abs($w1End - $w1Start);
                    $w4End = end($waves)['price'];

                    // W5 = W1 length
                    $targets[] = ['label' => 'W5 = W1 (1.0)', 'price' => round($w4End + $w1Len * 1.0 * $dir, 2), 'fib' => 1.0, 'type' => 'secondary', 'color' => '#8b5cf6'];

                    // W5 = 0.618 × (W1 to W3)
                    if (isset($byLabel['3'])) {
                        $w3End = $byLabel['3']['price'];
                        $w13Len = abs($w3End - $w1Start);
                        $targets[] = ['label' => 'W5 = 0.618 (W1-3)', 'price' => round($w4End + $w13Len * 0.618 * $dir, 2), 'fib' => 0.618, 'type' => 'primary', 'color' => '#34d399'];
                    }

                    // W5 = 1.618 × W1 (extended)
                    $targets[] = ['label' => 'W5 = 1.618 × W1', 'price' => round($w4End + $w1Len * 1.618 * $dir, 2), 'fib' => 1.618, 'type' => 'extended', 'color' => '#f59e0b'];

                    // Invalidation: W5 should not fail below W4 start (= W3 end)
                    if (isset($byLabel['3'])) {
                        $invalidation = ['price' => round($byLabel['3']['price'], 2), 'rule' => 'W5 must not fail below W3 end'];
                    }
                }
                break;

            case '5':
                // After W5: expect correction A-B-C
                $nextWave = 'A';
                $w5End = end($waves)['price'];
                if (isset($byLabel['1'])) {
                    $impLen = abs($w5End - $byLabel['1']['price']);
                    $corrDir = -$dir;

                    $targets[] = ['label' => 'Corr. retrace (0.382)', 'price' => round($w5End + $impLen * 0.382 * $corrDir, 2), 'fib' => 0.382, 'type' => 'secondary', 'color' => '#8b5cf6'];
                    $targets[] = ['label' => 'Corr. retrace (0.5)', 'price' => round($w5End + $impLen * 0.5 * $corrDir, 2), 'fib' => 0.5, 'type' => 'primary', 'color' => '#34d399'];
                    $targets[] = ['label' => 'Corr. retrace (0.618)', 'price' => round($w5End + $impLen * 0.618 * $corrDir, 2), 'fib' => 0.618, 'type' => 'extended', 'color' => '#f59e0b'];
                }
                break;

            case 'A':
                // After A: expect B bounce (50-78.6% of A)
                $nextWave = 'B';
                if (isset($byLabel['5'])) {
                    $w5End = $byLabel['5']['price'];
                    $aEnd = end($waves)['price'];
                    $aLen = abs($aEnd - $w5End);
                    $bounceDir = -$dir; // B goes against correction direction

                    $targets[] = ['label' => 'B retrace (0.5)', 'price' => round($aEnd - $aLen * 0.5 * $dir, 2), 'fib' => 0.5, 'type' => 'secondary', 'color' => '#8b5cf6'];
                    $targets[] = ['label' => 'B retrace (0.618)', 'price' => round($aEnd - $aLen * 0.618 * $dir, 2), 'fib' => 0.618, 'type' => 'primary', 'color' => '#34d399'];
                    $targets[] = ['label' => 'B retrace (0.786)', 'price' => round($aEnd - $aLen * 0.786 * $dir, 2), 'fib' => 0.786, 'type' => 'extended', 'color' => '#f59e0b'];
                }
                break;

            case 'B':
                // After B: expect C (typically = A or 1.618 × A)
                $nextWave = 'C';
                if (isset($byLabel['A'])) {
                    $aStart = $byLabel['5']['price'] ?? $byLabel['A']['price'];
                    $aEnd = $byLabel['A']['price'];
                    $aLen = abs($aEnd - $aStart);
                    $bEnd = end($waves)['price'];

                    $targets[] = ['label' => 'C = A (1.0)', 'price' => round($bEnd + $aLen * 1.0 * (-$dir), 2), 'fib' => 1.0, 'type' => 'primary', 'color' => '#34d399'];
                    $targets[] = ['label' => 'C = 1.618 × A', 'price' => round($bEnd + $aLen * 1.618 * (-$dir), 2), 'fib' => 1.618, 'type' => 'extended', 'color' => '#f59e0b'];
                }
                break;
        }

        // Add retracement lines for context (between last two waves)
        if (count($waves) >= 2) {
            $prev = $waves[count($waves) - 2];
            $curr = end($waves);
            $moveLen = abs($curr['price'] - $prev['price']);
            $moveDir = $curr['price'] > $prev['price'] ? -1 : 1;

            foreach ([0.236, 0.382, 0.5, 0.618, 0.786] as $level) {
                $retracements[] = [
                    'level' => $level,
                    'price' => round($curr['price'] + $moveLen * $level * $moveDir, 2),
                ];
            }
        }

        return [
            'nextWave' => $nextWave,
            'currentWave' => $currentWave,
            'targets' => $targets,
            'invalidation' => $invalidation,
            'retracements' => $retracements,
        ];
    }

    /**
     * Generate trade signals based on wave position.
     */
    private function generateSignals(array $waves, array $fibTargets, string $timeframe): array
    {
        if (empty($waves)) {
            return [];
        }

        $signals = [];
        $lastWave = end($waves);
        $prevWave = count($waves) >= 2 ? $waves[count($waves) - 2] : null;

        // Signal based on current wave position
        $label = $lastWave['label'];

        if (in_array($label, ['2', '4', 'B'])) {
            // End of corrective wave = potential entry with trend
            $direction = $lastWave['phase'] === 'CORRECTION' ? 'buy' : 'sell';
            if ($prevWave && $prevWave['price'] > $lastWave['price']) {
                $direction = 'buy'; // Pullback in uptrend
            } elseif ($prevWave && $prevWave['price'] < $lastWave['price']) {
                $direction = 'sell';
            }

            $signals[] = [
                'timeframe' => $timeframe,
                'engine' => 'elliott_wave',
                'direction' => $direction,
                'entry' => $lastWave['price'],
                'sl' => null,
                'tp' => null,
                'confluence_score' => 75,
                'candle_timestamp' => $lastWave['timestamp'],
            ];
        }

        if ($label === '5' || $label === 'C') {
            // End of impulse or correction = reversal signal
            $direction = $lastWave['swing_type'] === 'high' ? 'sell' : 'buy';

            $signals[] = [
                'timeframe' => $timeframe,
                'engine' => 'elliott_wave',
                'direction' => $direction,
                'entry' => $lastWave['price'],
                'sl' => null,
                'tp' => null,
                'confluence_score' => 85,
                'candle_timestamp' => $lastWave['timestamp'],
            ];
        }

        return $signals;
    }
}
