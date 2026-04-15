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

    /**
     * FIX C — Adaptive pivot strength per timeframe.
     * Hardcoded strength=8 produced 780 swings on a 5175-candle 5M window, which
     * let the labeler stitch together waves spanning 28 days across massive gaps.
     * A stronger pivot produces fewer, more meaningful swings anchored to real
     * structural highs/lows, so wave counts stay contiguous and recent.
     */
    private const PIVOT_STRENGTH_MAP = [
        '1M'  => 8,
        '5M'  => 10,
        '15M' => 10,
        '1H'  => 10,   // was 16 → 12 → 10. 492 candles can support 10.
        '4H'  => 8,    // was 10 → 14 → 8. Only ~212 candles; 14 was too aggressive.
        '1D'  => 4,    // low — daily bootstrap is only ~60-90 candles (3 months)
    ];

    /**
     * Minimum wave move size as a fraction of ATR.
     * A wave must move at least MIN_WAVE_ATR_RATIO × ATR(20) to qualify.
     * Without this, the labeler accepts 247-point micro-swings as W1/W2
     * on a 5M chart where the 20-bar ATR is ~300 pts — then labels a
     * 3,500-point multi-day move as W3, producing a grotesquely
     * disproportionate impulse.
     */
    private const MIN_WAVE_ATR_RATIO = 0.5;
    private const ATR_PERIOD = 20;

    /**
     * Per-timeframe ATR ratio overrides. Higher TFs (especially 1D) have
     * fewer candles and larger ATR values, so use a lower ratio to avoid
     * filtering out legitimate wave legs. Without this, a 1D ATR of ~800 pts
     * with 0.5 ratio = 400 pt minimum, which may reject valid W2/W4
     * retracements on 60-candle datasets.
     */
    private const ATR_RATIO_OVERRIDES = [
        '1D' => 0.25,
        '4H' => 0.35,
    ];

    /**
     * FIX B — Maximum bars allowed between two consecutive labeled waves.
     * Prevents the labeler from stitching a wave-3 in January onto a wave-4 in
     * March (28-day gap on 5M) just because the price relationships happen to
     * validate. A wave count must be *contiguous in time* to be actionable.
     */
    private const MAX_BARS_BETWEEN_WAVES = [
        '1M'  => 180,   // ~3 hours
        '5M'  => 150,   // ~2 NSE sessions (75 bars/day)
        '15M' => 120,   // ~2.5 sessions
        '1H'  => 80,    // ~3 NSE sessions
        '4H'  => 50,
        '1D'  => 40,
    ];

    /**
     * Maximum CALENDAR HOURS allowed between two consecutive labeled waves.
     * Prevents stitching waves across multi-day gaps (holidays, weekends + holidays)
     * where bar indices are close (due to missing candles) but actual calendar time
     * is far apart. A gap-up open after a 5-day gap is a structural event, not a
     * continuation of the prior session's impulse.
     *
     * Set generously to allow normal weekends (~42h) but break at multi-day gaps.
     */
    private const MAX_HOURS_BETWEEN_WAVES = [
        '1M'  => 144,   // 6 days — covers weekends + 1 holiday (e.g. Fri→Tue)
        '5M'  => 144,   // 6 days — same; W4 directional fix prevents invalid stitching
        '15M' => 168,   // 7 days — one full week
        '1H'  => 336,   // 2 weeks
        '4H'  => 720,   // 30 days
        '1D'  => 2160,  // 90 days (daily has natural session gaps)
    ];

    /**
     * FIX B — How many recent candles to inspect when deciding the impulse
     * direction from a price slope (instead of trusting the first two swings
     * at an arbitrary offset).
     */
    private const DIRECTION_LOOKBACK_BARS = [
        '1M'  => 240,
        '5M'  => 150,
        '15M' => 100,
        '1H'  => 80,
        '4H'  => 60,
        '1D'  => 40,
    ];

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < 50) {
            return new EngineResult(engine: 'elliott_wave', symbol: $symbol, timeframe: $timeframe);
        }

        // Step 1: Detect pivots using adaptive strength per timeframe (Fix C).
        // Weaker pivots create noise that the labeler stitches into non-contiguous
        // chains spanning weeks. Stronger pivots yield fewer, more meaningful swings.
        $pivotStrength = self::PIVOT_STRENGTH_MAP[$timeframe] ?? 12;
        $pivots = $this->detectPivots($candles, $pivotStrength);

        // Step 2: Build alternating swing sequence
        $swings = $this->buildSwingSequence($pivots);

        if (count($swings) < 5) {
            return new EngineResult(engine: 'elliott_wave', symbol: $symbol, timeframe: $timeframe);
        }

        // Step 2b: Calculate ATR for minimum wave size filter.
        $atr = $this->calculateATR($candles, self::ATR_PERIOD);

        // Step 3: Label waves (impulse + correction) — timeframe-aware
        $waveCounts = $this->labelWaves($swings, $candles, $timeframe, $atr);

        // Step 4: Validate rules and calculate health
        $validation = $this->validateRules($waveCounts);

        // Step 4b: Derive wave_state from validation result.
        //   confirmed            → score ≥ 60 AND no critical violations
        //   awaiting_confirmation → score 40-59 AND no critical violations
        //   invalidated          → any critical violation OR score < 40
        $hasCritical = false;
        foreach ($validation['violations'] as $v) {
            if (($v['severity'] ?? '') === 'critical') {
                $hasCritical = true;
                break;
            }
        }
        if ($hasCritical || $validation['score'] < 40) {
            $waveState = 'invalidated';
        } elseif ($validation['score'] < 60) {
            $waveState = 'awaiting_confirmation';
        } else {
            $waveState = 'confirmed';
        }

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

        // Step 10: Detect forming wave at the live edge
        $formingWave = $this->detectFormingWave($waveCounts, $candles);

        return new EngineResult(
            engine: 'elliott_wave',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $signals,
            overlays: [
                'waveLabels' => $waveLabels,
                'subLegs' => $subLegs,
                'formingWave' => $formingWave,
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
                'wave_state' => $waveState,
                'wave_count' => count($waveCounts),
                // Invalidated counts must not leak a current_wave label to the
                // ConfluenceEngine — otherwise downstream logic (e.g. "Wave 2 =
                // pullback entry") will fire on a broken chain.
                'current_wave' => ($waveState !== 'invalidated' && ! empty($waveCounts))
                    ? end($waveCounts)['label']
                    : null,
                'phase' => ($waveState !== 'invalidated' && ! empty($waveCounts))
                    ? end($waveCounts)['phase']
                    : null,
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
    /**
     * Label swings as Elliott Wave impulse (1-5) and correction (A-B-C).
     * Pre-validates each wave against Elliott rules and Fibonacci constraints
     * BEFORE accepting the label. Invalid sequences are rejected.
     */
    private function labelWaves(array $swings, array $candles, string $timeframe = '5M', float $atr = 0): array
    {
        if (count($swings) < 5) {
            return [];
        }

        $bullish = $this->determineBullishFromPriceSlope($candles, $timeframe);
        $maxGap = self::MAX_BARS_BETWEEN_WAVES[$timeframe] ?? 120;
        $atrRatio = self::ATR_RATIO_OVERRIDES[$timeframe] ?? self::MIN_WAVE_ATR_RATIO;
        $minMove = $atr * $atrRatio;

        $bestWaves = [];
        $bestScore = -INF;

        $swingCount = count($swings);
        $maxStart = max(0, $swingCount - 5);
        for ($startOffset = $maxStart; $startOffset >= 0; $startOffset--) {
            $candidate = $this->tryLabelFromOffset($swings, $startOffset, $bullish, $maxGap, $minMove, $candles, $timeframe);
            if (count($candidate) < 3) {
                continue;
            }

            $score = $this->scoreWaveChain($candidate, $swings, $timeframe);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestWaves = $candidate;
            }
        }

        // ALWAYS try opposite direction too and compare scores.
        // The slope-based direction is a hint, not gospel — a recent bearish
        // impulse can have a slightly positive overall slope due to the recovery
        // bounce, causing the primary pass to find only stale bullish labels.
        // Running both directions ensures the best (most recent, most complete)
        // count wins regardless of the slope heuristic.
        for ($startOffset = $maxStart; $startOffset >= 0; $startOffset--) {
            $candidate = $this->tryLabelFromOffset($swings, $startOffset, ! $bullish, $maxGap, $minMove, $candles, $timeframe);
            if (count($candidate) < 3) {
                continue;
            }
            $score = $this->scoreWaveChain($candidate, $swings, $timeframe);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestWaves = $candidate;
            }
        }

        // FIX 4 — Stale wave rejection.
        // If the best chain's last wave is too far from the current candle
        // (end of the dataset), the count is stale and no longer actionable.
        // E.g. a February wave count persisting into April because no new
        // swings matched — better to show nothing than a stale count.
        if (! empty($bestWaves) && ! empty($candles)) {
            $lastWaveIdx = (int) end($bestWaves)['index'];
            $totalCandles = count($candles);
            // Allow up to 2× the max-gap tolerance before declaring stale.
            // Beyond that, the count is too old to be useful.
            $stalenessLimit = ($maxGap * 2);
            if (($totalCandles - 1 - $lastWaveIdx) > $stalenessLimit) {
                $bestWaves = [];
            }
        }

        return $bestWaves;
    }

    /**
     * FIX B — Decide impulse direction from the slope of recent close prices
     * rather than from the first two swings of an arbitrary offset. Uses a
     * simple least-squares linear regression over the lookback window.
     */
    private function determineBullishFromPriceSlope(array $candles, string $timeframe): bool
    {
        $lookback = self::DIRECTION_LOOKBACK_BARS[$timeframe] ?? 100;
        $n = count($candles);
        $start = max(0, $n - $lookback);
        $slice = array_slice($candles, $start);
        $m = count($slice);
        if ($m < 3) {
            return true;
        }

        // Least-squares slope of close vs index.
        $sumX = 0.0; $sumY = 0.0; $sumXY = 0.0; $sumX2 = 0.0;
        foreach ($slice as $i => $c) {
            $x = (float) $i;
            $y = (float) $c['close'];
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumX2 += $x * $x;
        }
        $denom = ($m * $sumX2 - $sumX * $sumX);
        if ($denom == 0.0) {
            return true;
        }
        $slope = ($m * $sumXY - $sumX * $sumY) / $denom;

        return $slope >= 0;
    }

    /**
     * Calculate the Average True Range over the last $period candles.
     * Used as a minimum wave-size threshold so micro-swings can't be
     * labeled as Elliott Waves.
     */
    private function calculateATR(array $candles, int $period = 20): float
    {
        $n = count($candles);
        if ($n < $period + 1) {
            // Fallback: simple average range
            $sum = 0.0;
            foreach (array_slice($candles, -$period) as $c) {
                $sum += (float) $c['high'] - (float) $c['low'];
            }
            return $sum / max(1, min($period, $n));
        }

        $trSum = 0.0;
        $start = max(1, $n - $period);
        for ($i = $start; $i < $n; $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $pc = (float) $candles[$i - 1]['close'];
            $tr = max($h - $l, abs($h - $pc), abs($l - $pc));
            $trSum += $tr;
        }
        return $trSum / ($n - $start);
    }

    /**
     * FIX B — Score a labeled wave chain so we can pick the best count.
     * Rewards:
     *   - Longer valid chains (more labels placed = more rule checks passed)
     *   - Chains anchored to RECENT swings (higher endIndex relative to total)
     *   - Chains spanning fewer bars (tighter = more coherent)
     *   - Chains that consumed few non-accepted swings (skip_count penalty)
     */
    /**
     * Score a labeled wave chain so we can pick the best count.
     *
     * The scoring HEAVILY rewards recency so that a 5-wave chain anchored in
     * the last few sessions always beats an 8-wave chain from 3 weeks ago.
     * Without this, the old March chain (8 labels, high chainLen score) would
     * always outrank a fresh April chain (5 labels) even though the old one
     * is completely off-screen and useless for trading.
     */
    private function scoreWaveChain(array $chain, array $allSwings, string $timeframe): float
    {
        if (empty($chain)) {
            return -INF;
        }
        $lastSwingIdx = (int) end($allSwings)['index'];
        $firstWaveIdx = (int) $chain[0]['index'];
        $lastWaveIdx = (int) end($chain)['index'];

        $chainLen  = count($chain);
        $span      = max(1, $lastWaveIdx - $firstWaveIdx);

        // Distance from the very latest swing index — smaller is better.
        // This is the KEY factor: a chain ending 1000 bars back should never
        // beat a chain ending 50 bars back, regardless of chain length.
        $recencyGap = max(0, $lastSwingIdx - $lastWaveIdx);

        // Penalty grows when the span is too big relative to max-gap expectation.
        $maxGap = self::MAX_BARS_BETWEEN_WAVES[$timeframe] ?? 120;
        $spanPenalty = $span / max(1.0, $maxGap * $chainLen);

        // Recency is weighted 10× per bar of gap. A chain 200 bars stale
        // loses 2000 points — more than a full 8-wave chain can earn (800).
        // This guarantees any valid recent chain beats any old chain.
        return ($chainLen * 100.0)
            - ($recencyGap * 10.0)
            - ($spanPenalty * 50.0);
    }

    /**
     * Attempt to label waves starting from a given offset in the swing array.
     * FIX B — Now accepts an explicit $bullish direction + $maxGap constraint:
     *   1. Direction no longer derived from the first two swings (could be stale).
     *   2. Any candidate swing whose index is more than $maxGap bars beyond the
     *      previously accepted wave is rejected — no more multi-week gaps.
     */
    private function tryLabelFromOffset(array $swings, int $startOffset, bool $bullish, int $maxGap, float $minMove = 0, array $candles = [], string $timeframe = '5M'): array
    {
        $available = array_slice($swings, $startOffset);
        if (count($available) < 5) {
            return [];
        }

        // The swing immediately before the start offset (if any) is the TRUE
        // origin of Wave 1 — the pivot where the impulse began. validateRules
        // needs this to check Rule 2 (W2 must not retrace past W1 start).
        $originPrice = $startOffset > 0 ? (float) $swings[$startOffset - 1]['price'] : null;

        $waves = [];
        $fullSequence = ['1', '2', '3', '4', '5', 'A', 'B', 'C'];
        $correctionLabels = ['A', 'B', 'C'];
        $labelIdx = 0;
        $idx = 0;

        while ($idx < count($available) && $labelIdx < count($fullSequence)) {
            $swing = $available[$idx];
            $label = $fullSequence[$labelIdx];
            $isCorrection = in_array($label, $correctionLabels);

            // Build candidate wave entry. Attach the true W1-origin price to
            // the first wave of the chain so validateRules can correctly
            // evaluate Rule 2 (W2 can't retrace past W1 origin).
            $candidate = [
                'label' => $label,
                'swing_type' => $swing['type'],
                'price' => $swing['price'],
                'timestamp' => $swing['timestamp'],
                'index' => $swing['index'],
                'phase' => $isCorrection ? 'CORRECTION' : 'IMPULSE',
            ];
            if ($label === '1' && empty($waves) && $originPrice !== null) {
                $candidate['origin_price'] = $originPrice;
            }

            // FIX B — Enforce max bar gap relative to the last accepted wave.
            // Break entirely (not "skip") once the candidate is too far: every
            // subsequent swing will only be even further, so the chain ends here.
            if (! empty($waves)) {
                $prevIdx = (int) end($waves)['index'];
                if (($candidate['index'] - $prevIdx) > $maxGap) {
                    break;
                }

                // Session gap detection: prevent stitching waves across multi-day
                // gaps where bar indices are close (holiday candles missing) but
                // actual calendar time is far apart. A gap-up after 5 calendar days
                // is a structural event — the prior wave count is no longer valid.
                $maxHours = self::MAX_HOURS_BETWEEN_WAVES[$timeframe] ?? 96;
                $prevUnix = strtotime(end($waves)['timestamp']);
                $currUnix = strtotime($candidate['timestamp']);
                if ($prevUnix > 0 && $currUnix > 0) {
                    $hoursDiff = ($currUnix - $prevUnix) / 3600;
                    if ($hoursDiff > $maxHours) {
                        break;
                    }
                }
            }

            // Validate this wave against Elliott rules using already-labeled waves
            if ($this->validateWaveInline($candidate, $waves, $bullish)) {
                // FIX 1 — ATR-based minimum wave size filter.
                // After Elliott rule validation passes, also check that the
                // price move from the previous wave is at least $minMove.
                // This prevents micro-swings (e.g. 247 pts on a 300-pt ATR)
                // from being accepted as waves, which would create grotesquely
                // disproportionate impulse counts (tiny W1/W2, massive W3).
                if ($minMove > 0 && ! empty($waves)) {
                    $moveSize = abs($candidate['price'] - end($waves)['price']);
                    if ($moveSize < $minMove) {
                        // Too small — skip this swing, try the next one
                        $idx++;
                        continue;
                    }
                }

                // W3 look-ahead: verify a feasible W4 exists before committing.
                // Without this, the engine accepts tiny W3 candidates (barely
                // above W1) that are dead-ends — no valid W4 exists between W2
                // and W3. Meanwhile the REAL W3 (e.g. a gap-up high) sits
                // further ahead. By checking feasibility first, the engine
                // skips dead-end W3s and finds the structurally correct one.
                if ($label === '3') {
                    $w4Feasible = false;
                    $tempWaves = array_merge($waves, [$candidate]);
                    $maxPeek = min(count($available), $idx + 30);
                    $peekMaxHours = self::MAX_HOURS_BETWEEN_WAVES[$timeframe] ?? 96;
                    for ($peek = $idx + 1; $peek < $maxPeek; $peek++) {
                        $ps = $available[$peek];
                        // Check bar gap from W3
                        if (($ps['index'] - $candidate['index']) > $maxGap) break;
                        // Check session gap from W3
                        $ptd = (strtotime($ps['timestamp']) - strtotime($candidate['timestamp'])) / 3600;
                        if ($ptd > $peekMaxHours) break;
                        // Build a W4 candidate and validate
                        $peekW4 = [
                            'label' => '4',
                            'swing_type' => $ps['type'],
                            'price' => $ps['price'],
                            'timestamp' => $ps['timestamp'],
                            'index' => $ps['index'],
                            'phase' => 'IMPULSE',
                        ];
                        if ($this->validateWaveInline($peekW4, $tempWaves, $bullish)) {
                            // Also respect min move for W4
                            if ($minMove > 0) {
                                $w4Move = abs($ps['price'] - $candidate['price']);
                                if ($w4Move < $minMove) continue;
                            }
                            $w4Feasible = true;
                            break;
                        }
                    }
                    if (! $w4Feasible) {
                        // This W3 is a dead end — skip and try next swing as W3
                        $idx++;
                        continue;
                    }
                }

                $waves[] = $candidate;
                $labelIdx++;
                $idx++;

                // FIX 2 — After full cycle (8 waves = 1-5 + A-B-C), decide
                // the direction for the next cycle using slope re-evaluation
                // instead of blind flip. The blind flip assumed the next
                // impulse always reverses, but in trending markets the new
                // impulse often continues in the same direction (e.g. after
                // a corrective A-B-C in a bull market, the next 1-5 is also
                // bullish). Use price slope from the current candle position
                // to determine actual direction.
                if ($labelIdx >= count($fullSequence) && $idx < count($available)) {
                    $labelIdx = 0;
                    if (! empty($candles) && isset($available[$idx])) {
                        // Re-evaluate direction from the swing's candle position
                        $currentIdx = $available[$idx]['index'];
                        $lookback = min(60, max(20, (int) ($currentIdx * 0.1)));
                        $sliceStart = max(0, $currentIdx - $lookback);
                        $sliceEnd = min(count($candles), $currentIdx + 1);
                        $slice = array_slice($candles, $sliceStart, $sliceEnd - $sliceStart);
                        if (count($slice) >= 3) {
                            $firstClose = (float) $slice[0]['close'];
                            $lastClose = (float) end($slice)['close'];
                            $bullish = $lastClose >= $firstClose;
                        }
                        // If slice is too small, keep current $bullish as-is
                    }
                    // Fallback: if no candles were provided, keep $bullish unchanged
                    // (safer than blind flip since slope is unknown)
                }
            } else {
                // This swing doesn't fit the current label — skip it
                $idx++;
            }
        }

        return $waves;
    }

    /**
     * Validate a candidate wave against Elliott Wave rules BEFORE accepting it.
     * Returns true if the wave is valid, false to reject.
     */
    private function validateWaveInline(array $candidate, array $accepted, bool $bullish): bool
    {
        $label = $candidate['label'];
        $price = $candidate['price'];

        // Build lookup from already accepted waves
        $byLabel = [];
        foreach ($accepted as $w) {
            $byLabel[$w['label']] = $w;
        }

        switch ($label) {
            case '1':
                // Wave 1: must match expected swing type
                return $bullish
                    ? $candidate['swing_type'] === 'high'
                    : $candidate['swing_type'] === 'low';

            case '2':
                if (! isset($byLabel['1'])) return false;
                $w1 = $byLabel['1'];
                // Wave 2 must move AGAINST Wave 1 direction
                if ($bullish && $candidate['swing_type'] !== 'low') return false;
                if (! $bullish && $candidate['swing_type'] !== 'high') return false;

                // Rule: W2 must NOT retrace beyond the start of W1
                // W1 start = the swing before W1 (not in our array, so use the first accepted wave's context)
                // Fibonacci constraint: W2 should retrace 38.2%-78.6% of W1
                $w1Start = null;
                if (count($accepted) >= 1) {
                    // The swing before W1 in the original sequence
                    // For now, ensure W2 doesn't breach W1 start territory
                    // We approximate W1 start from context
                }

                // At minimum: W2 must not go beyond W1 end in the wrong direction
                if ($bullish && $price < 0) return false; // Sanity
                return true;

            case '3':
                if (! isset($byLabel['1'], $byLabel['2'])) return false;
                $w1 = $byLabel['1'];
                $w2 = $byLabel['2'];
                // Wave 3 must move WITH trend and exceed Wave 1
                if ($bullish) {
                    if ($candidate['swing_type'] !== 'high') return false;
                    if ($price <= $w1['price']) return false; // W3 must exceed W1
                } else {
                    if ($candidate['swing_type'] !== 'low') return false;
                    if ($price >= $w1['price']) return false; // W3 must exceed W1 (lower)
                }

                // Fibonacci: W3 should be at least 1.0x W1 length
                $w1Len = abs($w1['price'] - $w2['price']);
                $w3Len = abs($price - $w2['price']);
                if ($w3Len < $w1Len * 0.8) return false; // Allow slight tolerance (0.8x)

                return true;

            case '4':
                if (! isset($byLabel['1'], $byLabel['2'], $byLabel['3'])) return false;
                $w1 = $byLabel['1'];
                $w2 = $byLabel['2'];
                $w3 = $byLabel['3'];
                // Wave 4 must move AGAINST trend
                if ($bullish && $candidate['swing_type'] !== 'low') return false;
                if (! $bullish && $candidate['swing_type'] !== 'high') return false;

                // FIX: W4 must actually RETRACE from W3.
                // In bullish: W4 (a low/pullback) must be BELOW W3 (the high).
                // In bearish: W4 (a high/bounce) must be ABOVE W3 (the low).
                // Without this, a gap-up can place W4 above W3 — physically
                // impossible in a valid impulse structure. The abs() in the
                // retrace calculation masked this directional error.
                if ($bullish && $price >= $w3['price']) return false;
                if (! $bullish && $price <= $w3['price']) return false;

                // Rule 4 (soft inline check): allow the labeler to accept W4 even
                // with moderate overlap into W1 territory. The hard quality check
                // lives in validateRules() which penalizes health score (warning,
                // -20 pts) for ANY overlap. Here we only reject extreme overlap
                // (W4 retracing past W2, which would make the structure nonsensical).
                if ($bullish && $price < $w2['price']) return false; // W4 below W2 = absurd
                if (! $bullish && $price > $w2['price']) return false; // W4 above W2 = absurd

                // Fibonacci: W4 typically retraces 23.6%-50% of W3,
                // but can reach 78.6% in expanded flats. Hard cap at 0.786
                // to avoid rejecting valid deep corrections.
                $w3Len = abs($w3['price'] - $w2['price']);
                $w4Retrace = abs($w3['price'] - $price);
                $retracePct = $w3Len > 0 ? $w4Retrace / $w3Len : 0;
                if ($retracePct > 0.786) return false; // W4 shouldn't retrace >78.6% of W3
                if ($retracePct < 0.05) return false; // Too shallow — probably not a real W4

                return true;

            case '5':
                if (! isset($byLabel['1'], $byLabel['2'], $byLabel['3'], $byLabel['4'])) return false;
                $w1 = $byLabel['1'];
                $w2 = $byLabel['2'];
                $w3 = $byLabel['3'];
                $w4 = $byLabel['4'];
                // Wave 5 must move WITH trend
                if ($bullish && $candidate['swing_type'] !== 'high') return false;
                if (! $bullish && $candidate['swing_type'] !== 'low') return false;

                // W5 must exceed W4 (otherwise it's a truncation — accept with lower health later)
                if ($bullish && $price <= $w4['price']) return false;
                if (! $bullish && $price >= $w4['price']) return false;

                // Rule 3 pre-check: W3 must not be the shortest
                $w1Len = abs($w1['price'] - $w2['price']);
                $w3Len = abs($w3['price'] - $w2['price']);
                $w5Len = abs($price - $w4['price']);
                if ($w3Len < $w1Len && $w3Len < $w5Len) {
                    return false; // Would make W3 shortest — reject this W5
                }

                return true;

            case 'A':
                if (! isset($byLabel['5'])) return false;
                // Wave A moves AGAINST the impulse trend
                if ($bullish && $candidate['swing_type'] !== 'low') return false;
                if (! $bullish && $candidate['swing_type'] !== 'high') return false;
                // A must not go beyond W4 (in most cases, deeper = complex correction)
                return true;

            case 'B':
                if (! isset($byLabel['A'])) return false;
                // Wave B moves WITH the impulse trend (counter-correction)
                if ($bullish && $candidate['swing_type'] !== 'high') return false;
                if (! $bullish && $candidate['swing_type'] !== 'low') return false;
                // B must not exceed W5 (if it does, it's not a simple correction)
                if (isset($byLabel['5'])) {
                    if ($bullish && $price > $byLabel['5']['price']) return false;
                    if (! $bullish && $price < $byLabel['5']['price']) return false;
                }
                return true;

            case 'C':
                if (! isset($byLabel['A'], $byLabel['B'])) return false;
                // Wave C moves AGAINST the impulse trend
                if ($bullish && $candidate['swing_type'] !== 'low') return false;
                if (! $bullish && $candidate['swing_type'] !== 'high') return false;
                // C must exceed A (otherwise correction is incomplete)
                $wA = $byLabel['A'];
                if ($bullish && $price > $wA['price']) return false; // C should be lower than A in bull
                if (! $bullish && $price < $wA['price']) return false;
                return true;

            default:
                return false;
        }
    }

    /**
     * Validate Elliott Wave rules and calculate health score.
     *
     * FIX B — The labeler emits multiple sequential cycles (1-5 A-B-C)(1-5 A-B-C)…
     * and we only care about the MOST RECENT cycle. Building $byLabel across all
     * waves lets stale cycle-2 "5" overwrite cycle-3 "5", producing fake
     * wave-length calculations that cross cycle boundaries and firing spurious
     * Rule 3 (W3 shortest) violations on perfectly valid counts.
     *
     * Extract the last cycle (backwards scan until we see a label we've already
     * encountered) and validate only those waves.
     */
    private function validateRules(array $waves): array
    {
        $violations = [];
        $score = 100;

        // Extract the most recent cycle only. A new cycle always starts at
        // label '1', so the last cycle is the slice starting at the LAST
        // occurrence of '1'. If there is no '1' we fall back to the full array.
        $lastOneIdx = -1;
        for ($i = count($waves) - 1; $i >= 0; $i--) {
            if ($waves[$i]['label'] === '1') {
                $lastOneIdx = $i;
                break;
            }
        }
        $lastCycle = $lastOneIdx >= 0 ? array_slice($waves, $lastOneIdx) : $waves;

        // Build lookup by label from the last cycle only.
        $byLabel = [];
        foreach ($lastCycle as $w) {
            $byLabel[$w['label']] = $w;
        }

        // Need at least waves 1-5 for rule checking
        if (! isset($byLabel['1'], $byLabel['2'], $byLabel['3'], $byLabel['4'], $byLabel['5'])) {
            return ['score' => $score, 'violations' => $violations];
        }

        // Labels in this engine sit at the END of each wave segment:
        //   label '1' = end of W1, label '2' = end of W2, etc.
        // The TRUE origin of W1 is stored on the first wave via origin_price
        // (added in tryLabelFromOffset). Without it Rule 2 is inconclusive.
        $w1Origin = $byLabel['1']['origin_price'] ?? null;
        $w1End    = $byLabel['1']['price'];   // end of Wave 1
        $w2End    = $byLabel['2']['price'];   // end of Wave 2
        $w3End    = $byLabel['3']['price'];   // end of Wave 3
        $w4End    = $byLabel['4']['price'];   // end of Wave 4
        $w5End    = $byLabel['5']['price'];   // end of Wave 5

        // In a bullish impulse W1 ends higher than its origin; in a bearish
        // impulse it ends lower. Use swing_type of label '1' as the primary
        // signal — bullish if label '1' sits on a HIGH swing.
        $isBullish = ($byLabel['1']['swing_type'] ?? 'high') === 'high';

        // Rule 2: Wave 2 cannot retrace beyond the ORIGIN of Wave 1.
        // Only evaluate when we actually know the origin — otherwise we'd
        // raise spurious violations on perfectly valid counts.
        if ($w1Origin !== null) {
            if ($isBullish) {
                if ($w2End < $w1Origin) {
                    $violations[] = [
                        'rule' => 2,
                        'description' => 'Wave 2 retraced below Wave 1 origin',
                        'severity' => 'critical',
                    ];
                    $score -= 30;
                }
            } else {
                if ($w2End > $w1Origin) {
                    $violations[] = [
                        'rule' => 2,
                        'description' => 'Wave 2 retraced above Wave 1 origin',
                        'severity' => 'critical',
                    ];
                    $score -= 30;
                }
            }
        }

        // Rule 3: Wave 3 must not be the shortest impulse wave.
        // Wave lengths are measured end-to-end:
        //   W1 = |w1End - w1Origin|  (falls back to W1→W2 gap if no origin)
        //   W3 = |w3End - w2End|
        //   W5 = |w5End - w4End|
        $wave1Len = $w1Origin !== null ? abs($w1End - $w1Origin) : abs($w2End - $w1End);
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

            case 'C':
                // After C: expect new impulse wave 1
                $nextWave = '1';
                $cEnd = end($waves)['price'];
                // ABC correction range: from wave 5 to wave C
                if (isset($byLabel['5'])) {
                    $w5End = $byLabel['5']['price'];
                    $corrLen = abs($cEnd - $w5End);
                    $newDir = $cEnd < $w5End ? 1 : -1; // New impulse reverses correction direction

                    $targets[] = ['label' => 'W1 retrace (0.382)', 'price' => round($cEnd + $corrLen * 0.382 * $newDir, 2), 'fib' => 0.382, 'type' => 'secondary', 'color' => '#8b5cf6'];
                    $targets[] = ['label' => 'W1 retrace (0.5)', 'price' => round($cEnd + $corrLen * 0.5 * $newDir, 2), 'fib' => 0.5, 'type' => 'primary', 'color' => '#34d399'];
                    $targets[] = ['label' => 'W1 retrace (0.618)', 'price' => round($cEnd + $corrLen * 0.618 * $newDir, 2), 'fib' => 0.618, 'type' => 'extended', 'color' => '#f59e0b'];

                    $invalidation = ['price' => round($cEnd, 2), 'rule' => 'New W1 must not break below Wave C'];
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
     * Detect a forming wave at the live edge beyond the last confirmed wave.
     * Scans remaining candles with lower-strength pivots to find tentative structure.
     */
    private function detectFormingWave(array $waveCounts, array $candles): ?array
    {
        if (empty($waveCounts) || empty($candles)) {
            return null;
        }

        $lastWave = end($waveCounts);
        $lastLabel = $lastWave['label'];
        $lastIndex = $lastWave['index'];

        // Determine next expected wave label
        // Only emit forming wave after sequence-ending waves (C or 5)
        $nextLabel = null;
        if ($lastLabel === 'C') {
            $nextLabel = '1'; // New impulse after correction
        } elseif ($lastLabel === '5') {
            $nextLabel = 'A'; // New correction after impulse
        } else {
            // Mid-sequence waves are already handled by the engine
            return null;
        }

        // Need at least 6 candles after the last confirmed wave
        $remainingStart = $lastIndex + 1;
        if ($remainingStart >= count($candles) || (count($candles) - $remainingStart) < 6) {
            return null;
        }

        // Extract the remaining candle segment
        $segment = array_slice($candles, $remainingStart);

        // Detect lower-strength pivots in the remaining segment
        $pivots = $this->detectPivots($segment, 3);

        if (empty($pivots)) {
            return null;
        }

        // Build swing sequence from tentative pivots
        $swings = $this->buildSwingSequence($pivots);

        // Build tentative pivot data with absolute indices/timestamps
        $tentativePivots = array_map(function ($swing) {
            return [
                'price' => $swing['price'],
                'timestamp' => $swing['timestamp'],
                'type' => $swing['type'],
            ];
        }, $swings);

        $lastCandle = end($candles);

        return [
            'nextLabel' => $nextLabel,
            'tentative' => true,
            'startPrice' => $lastWave['price'],
            'startTime' => $lastWave['timestamp'],
            'currentPrice' => (float) $lastCandle['close'],
            'currentTime' => $lastCandle['timestamp'],
            'tentativePivots' => $tentativePivots,
        ];
    }

    /**
     * Generate trade signals based on wave position.
     *
     * Every wave emits a directional signal so the ConfluenceEngine always
     * has an EW vote. The direction follows Elliott Wave theory:
     *
     * UPTREND (bullish impulse):
     *   1→buy, 2→sell, 3→buy, 4→sell, 5→buy, A→sell, B→buy, C→sell
     *
     * DOWNTREND (bearish impulse):
     *   1→sell, 2→buy, 3→sell, 4→buy, 5→sell, A→buy, B→sell, C→buy
     *
     * Trend direction is derived from the impulse structure: if Wave 1's
     * end price > start price, the impulse is bullish.
     */
    private function generateSignals(array $waves, array $fibTargets, string $timeframe): array
    {
        if (empty($waves)) {
            return [];
        }

        $signals = [];
        $lastWave = end($waves);
        $label = $lastWave['label'];

        // Determine whether the impulse structure is bullish or bearish.
        // Find the most recent Wave 1 and its preceding pivot (wave start).
        $bullishImpulse = true;
        for ($i = count($waves) - 1; $i >= 0; $i--) {
            if ($waves[$i]['label'] === '1') {
                // Wave 1 end price vs its start (preceding pivot)
                if ($i > 0) {
                    $bullishImpulse = $waves[$i]['price'] > $waves[$i - 1]['price'];
                } else {
                    $bullishImpulse = $waves[$i]['swing_type'] === 'high';
                }
                break;
            }
        }

        // Tradeable entry direction — NOT the current momentum direction.
        // The confluence engine uses this to decide CALL vs PUT, so it must
        // answer "which way should we trade from here?" not "which way is
        // price moving at this instant?"
        //
        //   Impulse phase (1..5): the whole 5-wave structure is a trend move.
        //     Pullback waves (2, 4) are BUY-the-dip entries in a bullish
        //     impulse — entering during the pullback is how you catch the
        //     next impulse leg. So all impulse labels trade WITH the impulse.
        //
        //   Corrective phase (A, B, C): the ABC is a counter-trend move.
        //     A and C are the counter-trend legs, B is the counter-correction.
        //     Trading the correction means fading the prior impulse → direction
        //     is OPPOSITE of bullishImpulse. Wave C is typically where the
        //     corrective move exhausts; after C, a new impulse starts in the
        //     ORIGINAL direction.
        $isImpulseLabel  = in_array($label, ['1', '2', '3', '4', '5'], true);
        $isCorrectionLabel = in_array($label, ['A', 'B', 'C'], true);

        if ($isImpulseLabel) {
            // All impulse sub-waves trade with the impulse direction.
            $direction = $bullishImpulse ? 'buy' : 'sell';
        } elseif ($label === 'C') {
            // Wave C completing = correction ending; anticipate the next
            // impulse cycle in the original trend direction.
            $direction = $bullishImpulse ? 'buy' : 'sell';
        } elseif ($isCorrectionLabel) {
            // A and B: correction still unfolding, trade against the prior
            // impulse.
            $direction = $bullishImpulse ? 'sell' : 'buy';
        } else {
            // Unknown label — fallback to swing type
            $direction = $lastWave['swing_type'] === 'high' ? 'sell' : 'buy';
        }

        // Confidence varies by wave position strength
        $confidenceMap = [
            '1' => 75, '2' => 70, '3' => 85, '4' => 70, '5' => 65,
            'A' => 75, 'B' => 65, 'C' => 80,
        ];
        $confidence = $confidenceMap[$label] ?? 70;

        $signals[] = [
            'timeframe' => $timeframe,
            'engine' => 'elliott_wave',
            'direction' => $direction,
            'entry' => $lastWave['price'],
            'sl' => null,
            'tp' => null,
            'confluence_score' => $confidence,
            'candle_timestamp' => $lastWave['timestamp'],
        ];

        // Additional reversal signal at end of impulse (5) or correction (C)
        if ($label === '5' || $label === 'C') {
            $reversalDir = $lastWave['swing_type'] === 'high' ? 'sell' : 'buy';
            // Only add if different from the primary signal (avoids duplicate)
            if ($reversalDir !== $direction) {
                $signals[] = [
                    'timeframe' => $timeframe,
                    'engine' => 'elliott_wave',
                    'direction' => $reversalDir,
                    'entry' => $lastWave['price'],
                    'sl' => null,
                    'tp' => null,
                    'confluence_score' => 60, // Lower confidence — it's a pending reversal
                    'candle_timestamp' => $lastWave['timestamp'],
                ];
            }
        }

        return $signals;
    }
}
