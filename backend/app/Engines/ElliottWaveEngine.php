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

        // Step 8: Estimate wave completion time
        $timeEstimate = $this->estimateWaveTime($waveCounts, $timeframe);

        // Step 9: Detect sub-legs within each main wave segment
        $subLegs = $this->detectSubLegs($waveCounts, $candles, $degree);

        return new EngineResult(
            engine: 'elliott_wave',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: [
                'waveLabels' => $waveLabels,
                'subLegs' => $subLegs,
                'fibTargets' => $fibTargets,
                'nextTargets' => $nextTargets,
                'timeEstimate' => $timeEstimate,
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
     * Detect sub-legs (lower degree pivots) within each main wave segment.
     * Impulse waves (1, 3, 5) get sub-waves: i, ii, iii, iv, v
     * Corrective waves (2, 4, A, B, C) get sub-waves: a, b, c
     */
    private function detectSubLegs(array $waveCounts, array $candles, string $degree): array
    {
        if (count($waveCounts) < 2) {
            return [];
        }

        $subLegs = [];
        $subStrength = 3; // Lower strength = more sensitive pivot detection

        // Map degree to sub-degree
        $subDegreeMap = [
            'Primary' => 'Intermediate',
            'Intermediate' => 'Minor',
            'Minor' => 'Minute',
            'Minute' => 'Minuette',
            'Minuette' => 'Sub-Minuette',
            'Sub-Minuette' => 'Micro',
        ];
        $subDegree = $subDegreeMap[$degree] ?? 'Micro';

        // Impulse sub-labels and corrective sub-labels
        $impulseSubLabels = ['i', 'ii', 'iii', 'iv', 'v'];
        $correctiveSubLabels = ['a', 'b', 'c'];

        for ($w = 0; $w < count($waveCounts) - 1; $w++) {
            $startWave = $waveCounts[$w];
            $endWave = $waveCounts[$w + 1];
            $startIdx = $startWave['index'];
            $endIdx = $endWave['index'];
            $parentLabel = $endWave['label']; // The wave this segment belongs to

            // Need enough candles for sub-pivot detection
            if ($endIdx - $startIdx < $subStrength * 2 + 3) {
                continue;
            }

            // Extract candle segment
            $segment = array_slice($candles, $startIdx, $endIdx - $startIdx + 1);
            if (count($segment) < $subStrength * 2 + 3) {
                continue;
            }

            // Detect sub-pivots within this segment
            $subPivots = [];
            for ($i = $subStrength; $i < count($segment) - $subStrength; $i++) {
                $isHigh = true;
                $isLow = true;
                $high = (float) $segment[$i]['high'];
                $low = (float) $segment[$i]['low'];

                for ($j = 1; $j <= $subStrength; $j++) {
                    if ((float) $segment[$i - $j]['high'] >= $high || (float) $segment[$i + $j]['high'] >= $high) {
                        $isHigh = false;
                    }
                    if ((float) $segment[$i - $j]['low'] <= $low || (float) $segment[$i + $j]['low'] <= $low) {
                        $isLow = false;
                    }
                }

                if ($isHigh) {
                    $subPivots[] = ['type' => 'high', 'price' => $high, 'timestamp' => $segment[$i]['timestamp'], 'index' => $startIdx + $i];
                }
                if ($isLow) {
                    $subPivots[] = ['type' => 'low', 'price' => $low, 'timestamp' => $segment[$i]['timestamp'], 'index' => $startIdx + $i];
                }
            }

            usort($subPivots, fn ($a, $b) => $a['index'] <=> $b['index']);

            // Build alternating swing sequence from sub-pivots
            $subSwings = $this->buildSwingSequence($subPivots);

            if (count($subSwings) < 2) {
                continue;
            }

            // Determine sub-labels based on parent wave type
            $isImpulseWave = in_array($parentLabel, ['1', '3', '5']);
            $subLabels = $isImpulseWave ? $impulseSubLabels : $correctiveSubLabels;

            // Assign labels to sub-swings (limit to available labels)
            $labelCount = min(count($subSwings), count($subLabels));
            for ($s = 0; $s < $labelCount; $s++) {
                $sub = $subSwings[$s];
                $subLegs[] = [
                    'label' => $subLabels[$s],
                    'type' => $sub['type'],
                    'price' => $sub['price'],
                    'timestamp' => $sub['timestamp'],
                    'parentWave' => $parentLabel,
                    'degree' => $subDegree,
                    'isCorrection' => ! $isImpulseWave,
                ];
            }
        }

        return $subLegs;
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

        // Determine trend direction: in a bullish impulse, wave 1 (high) > wave 2 (low pullback)
        // In a bearish impulse, wave 1 (low) < wave 2 (high pullback)
        $isBullish = true;
        if (isset($byLabel['1'], $byLabel['2'])) {
            $isBullish = $byLabel['1']['price'] > $byLabel['2']['price'];
        } elseif (isset($byLabel['1'])) {
            $isBullish = ($byLabel['1']['type'] ?? '') === 'high';
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
     * Estimate wave completion time using Fibonacci time ratios.
     * Calculates duration of each completed wave and projects remaining time.
     */
    private function estimateWaveTime(array $waves, string $timeframe): array
    {
        if (count($waves) < 2) {
            return ['durations' => [], 'estimate' => null];
        }

        // Calculate duration of each completed wave in minutes
        $durations = [];
        for ($i = 1; $i < count($waves); $i++) {
            $start = \Carbon\Carbon::parse($waves[$i - 1]['timestamp']);
            $end = \Carbon\Carbon::parse($waves[$i]['timestamp']);
            $minutes = $start->diffInMinutes($end);
            $durations[] = [
                'from' => $waves[$i - 1]['label'],
                'to' => $waves[$i]['label'],
                'minutes' => $minutes,
                'startTime' => $waves[$i - 1]['timestamp'],
                'endTime' => $waves[$i]['timestamp'],
            ];
        }

        $currentWave = end($waves);
        $currentLabel = $currentWave['label'];
        $currentStart = \Carbon\Carbon::parse($currentWave['timestamp']);
        $elapsed = $currentStart->diffInMinutes(now()->utc());

        // Fibonacci time ratios for wave duration estimation
        $w1Duration = null;
        $w3Duration = null;
        $wADuration = null;
        $prevWaveDuration = null;

        foreach ($durations as $d) {
            if ($d['from'] === '1' && $d['to'] === '2') $w1Duration = $d['minutes'];
            if ($d['from'] === '3' || ($d['from'] === '2' && $d['to'] === '3')) $w3Duration = $d['minutes'];
            if ($d['from'] === 'A' || ($d['from'] === '5' && $d['to'] === 'A')) $wADuration = $d['minutes'];
        }
        if (count($durations) > 0) {
            $prevWaveDuration = end($durations)['minutes'];
        }

        // Estimate based on current wave position
        $primaryMinutes = null;
        $extendedMinutes = null;
        $formula = '';

        switch ($currentLabel) {
            case '1':
                // Wave 1 just started — no basis for estimate yet
                $formula = 'First wave — no prior data';
                break;
            case '2':
                if ($w1Duration) {
                    $primaryMinutes = (int) round($w1Duration * 0.5);
                    $extendedMinutes = (int) round($w1Duration * 0.618);
                    $formula = "0.5 × W1 ({$w1Duration}m) = {$primaryMinutes}m";
                }
                break;
            case '3':
                if ($w1Duration) {
                    $primaryMinutes = (int) round($w1Duration * 1.618);
                    $extendedMinutes = (int) round($w1Duration * 2.618);
                    $formula = "1.618 × W1 ({$w1Duration}m) = {$primaryMinutes}m";
                }
                break;
            case '4':
                if ($w3Duration) {
                    $primaryMinutes = (int) round($w3Duration * 0.382);
                    $extendedMinutes = (int) round($w3Duration * 0.5);
                    $formula = "0.382 × W3 ({$w3Duration}m) = {$primaryMinutes}m";
                } elseif ($w1Duration) {
                    $primaryMinutes = (int) round($w1Duration * 0.5);
                    $extendedMinutes = (int) round($w1Duration);
                    $formula = "0.5 × W1 ({$w1Duration}m) = {$primaryMinutes}m";
                }
                break;
            case '5':
                if ($w1Duration) {
                    $primaryMinutes = $w1Duration; // W5 ≈ W1
                    $extendedMinutes = (int) round($w1Duration * 1.618);
                    $formula = "W5 = W1 ({$w1Duration}m)";
                }
                break;
            case 'A':
                if ($w1Duration && $w3Duration) {
                    $impulseDuration = $w1Duration + $w3Duration;
                    $primaryMinutes = (int) round($impulseDuration * 0.382);
                    $extendedMinutes = (int) round($impulseDuration * 0.5);
                    $formula = "0.382 × impulse ({$impulseDuration}m) = {$primaryMinutes}m";
                } elseif ($prevWaveDuration) {
                    $primaryMinutes = $prevWaveDuration;
                    $extendedMinutes = (int) round($prevWaveDuration * 1.618);
                    $formula = "≈ prev wave ({$prevWaveDuration}m)";
                }
                break;
            case 'B':
                if ($wADuration) {
                    $primaryMinutes = (int) round($wADuration * 0.5);
                    $extendedMinutes = (int) round($wADuration * 0.786);
                    $formula = "0.5 × W(A) ({$wADuration}m) = {$primaryMinutes}m";
                } elseif ($prevWaveDuration) {
                    $primaryMinutes = (int) round($prevWaveDuration * 0.618);
                    $extendedMinutes = $prevWaveDuration;
                    $formula = "0.618 × prev ({$prevWaveDuration}m) = {$primaryMinutes}m";
                }
                break;
            case 'C':
                if ($wADuration) {
                    $primaryMinutes = $wADuration; // C ≈ A
                    $extendedMinutes = (int) round($wADuration * 1.618);
                    $formula = "C = A ({$wADuration}m)";
                }
                break;
        }

        $remaining = null;
        $primaryEta = null;
        $extendedEta = null;
        $progressPct = 0;

        if ($primaryMinutes !== null) {
            $remaining = max(0, $primaryMinutes - $elapsed);
            $primaryEta = now()->utc()->addMinutes($remaining)->toIso8601String();
            $extendedEta = $extendedMinutes
                ? now()->utc()->addMinutes(max(0, $extendedMinutes - $elapsed))->toIso8601String()
                : null;
            $progressPct = $primaryMinutes > 0 ? min(100, round($elapsed / $primaryMinutes * 100)) : 0;
        }

        return [
            'currentWave' => $currentLabel,
            'currentWaveStart' => $currentWave['timestamp'],
            'elapsed' => $elapsed,
            'durations' => $durations,
            'estimate' => $primaryMinutes ? [
                'primaryMinutes' => $primaryMinutes,
                'extendedMinutes' => $extendedMinutes,
                'remaining' => $remaining,
                'primaryEta' => $primaryEta,
                'extendedEta' => $extendedEta,
                'progressPct' => $progressPct,
                'formula' => $formula,
            ] : null,
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
