<?php

declare(strict_types=1);

namespace App\Engines;

/**
 * ConfluenceEngine v3.2 — Single source of truth for direction + confidence.
 *
 * Layers implemented:
 *   L1: Bug fixes — weighted direction instead of raw signal count
 *   L2: HTF conflict gate — penalizes when HTF disagrees with signal direction
 *   L3: Tiered engine + timeframe weighting
 *   L4: Minimum conditions gate — show WAIT when evidence is weak
 *   L5: Dynamic confidence adjustments (±modifiers, capped 30-85%)
 *   L6: Time decay + staleness (applied by frontend using computed_at)
 *
 * Scoring weights (unchanged):
 *   Context (HTF wave position):       max 35 points
 *   Levels  (OB + FVG + OTE + VWAP):   max 35 points
 *   Trigger (BOS/CHOCH + pattern):      max 30 points
 *
 * New outputs:
 *   - callPut: 'BUY CALL' | 'BUY PUT' | 'WAIT' (simplified for basic users)
 *   - userReason: plain-English explanation of why this recommendation
 *   - marketTrend: simplified trend summary {label, emoji, direction}
 *   - adjustedPct: final confidence after all adjustments (30-85 cap)
 *   - conflict: bool — true when HTF trend opposes signal direction
 *   - conflictNote: string explaining the conflict
 *   - htfBias: 'BULL' | 'BEAR' | 'NEUTRAL' — passed through for frontend
 *   - gateResult: which minimum conditions passed/failed
 */
class ConfluenceEngine
{
    /**
     * Engine weights for direction voting (Layer 3).
     * Higher weight = more influence on BULL/BEAR direction.
     */
    private const ENGINE_WEIGHTS = [
        'elliott_wave'     => 3.0,
        'market_structure'  => 3.0,
        'order_block'      => 2.0,
        'fvg'              => 1.5,
        'smc'              => 2.0,
        'vwap'             => 1.0,
        'price_action'     => 1.0,
    ];

    /**
     * Score confluence across all engine results.
     *
     * @param  string  $htfBias   'BULL'|'BEAR'|'NEUTRAL' — from MTF analysis (1D+4H+1H trends)
     * @param  string  $timeframe Active timeframe being analyzed
     */
    public function score(
        EngineResult $ewResult,
        EngineResult $msResult,
        EngineResult $obResult,
        EngineResult $fvgResult,
        EngineResult $smcResult,
        EngineResult $vwapResult,
        EngineResult $paResult,
        float $currentPrice,
        string $htfBias = 'NEUTRAL',
        string $timeframe = '5M',
    ): array {
        // ── Phase 1: Compute sub-scores (unchanged logic) ──
        $contextScore = $this->scoreContext($ewResult, $msResult);
        $levelsScore = $this->scoreLevels($obResult, $fvgResult, $smcResult, $vwapResult, $currentPrice);
        $triggerScore = $this->scoreTrigger($msResult, $paResult);

        $total = $contextScore['score'] + $levelsScore['score'] + $triggerScore['score'];
        $maxTotal = 35 + 35 + 30; // 100

        // ── Phase 2: Weighted direction (Layer 3) ──
        $engineResults = [
            'elliott_wave'     => $ewResult,
            'market_structure' => $msResult,
            'order_block'      => $obResult,
            'fvg'              => $fvgResult,
            'smc'              => $smcResult,
            'vwap'             => $vwapResult,
            'price_action'     => $paResult,
        ];

        $weightedBull = 0.0;
        $weightedBear = 0.0;
        $rawBullCount = 0;
        $rawBearCount = 0;
        $agreeingEngines = 0; // how many engines have at least 1 directional signal

        foreach ($engineResults as $engineKey => $result) {
            $engineWeight = self::ENGINE_WEIGHTS[$engineKey] ?? 1.0;
            $engineBull = 0;
            $engineBear = 0;

            foreach ($result->signals as $signal) {
                $dir = $signal['direction'] ?? '';
                if ($dir === 'buy') {
                    $engineBull++;
                    $rawBullCount++;
                }
                if ($dir === 'sell') {
                    $engineBear++;
                    $rawBearCount++;
                }
            }

            // Each engine votes once (net direction) with its weight
            if ($engineBull > $engineBear) {
                $weightedBull += $engineWeight;
                $agreeingEngines++;
            } elseif ($engineBear > $engineBull) {
                $weightedBear += $engineWeight;
                $agreeingEngines++;
            }
        }

        $direction = 'NEUTRAL';
        if ($weightedBull > $weightedBear) {
            $direction = 'BULL';
        } elseif ($weightedBear > $weightedBull) {
            $direction = 'BEAR';
        }

        // ── Phase 3: HTF Conflict Gate (Layer 2) ──
        $conflict = false;
        $conflictNote = '';

        if ($htfBias !== 'NEUTRAL' && $direction !== 'NEUTRAL' && $htfBias !== $direction) {
            $conflict = true;
            $conflictNote = "HTF bias ({$htfBias}) conflicts with signal ({$direction})";
        }

        // ── Phase 4: Minimum Conditions Gate (Layer 4) ──
        $gateChecks = [
            'context_ok' => $contextScore['score'] >= 20,        // EW health + wave + trend
            'levels_ok' => $levelsScore['score'] >= 15,          // OB/FVG/OTE near price
            'trigger_ok' => $triggerScore['score'] >= 10,        // BOS/CHOCH confirmed
            'engines_agree' => $agreeingEngines >= 2,            // At least 2 engines agree
            'wave_health_ok' => ($ewResult->metadata['health_score'] ?? 0) >= 50,
        ];

        $gatesPassed = count(array_filter($gateChecks));
        $gateTotalRequired = 3; // need at least 3 of 5 gates to show directional signal
        $gatesOk = $gatesPassed >= $gateTotalRequired;

        // ── Phase 5: Dynamic Confidence Adjustments (Layer 5) ──
        $basePct = $maxTotal > 0 ? round($total / $maxTotal * 100) : 0;
        $adjustments = [];
        $adjustedPct = (float) $basePct;

        // +10 if HTF aligned with signal
        if ($htfBias !== 'NEUTRAL' && $htfBias === $direction) {
            $adjustedPct += 10;
            $adjustments[] = 'HTF aligned (+10%)';
        }

        // -25 if HTF conflicts with signal
        if ($conflict) {
            $adjustedPct -= 25;
            $adjustments[] = 'HTF conflict (-25%)';
        }

        // +8 if all 3 level types present (OB + FVG + OTE)
        $hasOb = ! empty(array_filter($obResult->overlays['orderBlocks'] ?? [], fn ($b) => ($b['status'] ?? '') === 'fresh'));
        $hasFvg = ! empty(array_filter($fvgResult->overlays['fvgs'] ?? [], fn ($f) => ($f['fill_pct'] ?? 100) < 50));
        $hasOte = ! empty($smcResult->overlays['oteZones'] ?? []);
        if ($hasOb && $hasFvg && $hasOte) {
            $adjustedPct += 8;
            $adjustments[] = 'Triple confluence OB+FVG+OTE (+8%)';
        }

        // +5 if CHOCH confirmed (not just BOS)
        $bos = $msResult->overlays['bos'] ?? [];
        $recentBos = array_slice($bos, -3);
        $hasChoch = false;
        foreach ($recentBos as $b) {
            if (($b['type'] ?? '') === 'choch') {
                $hasChoch = true;
            }
        }
        if ($hasChoch) {
            $adjustedPct += 5;
            $adjustments[] = 'CHOCH confirmed (+5%)';
        }

        // -15 if wave health < 50
        $waveHealth = $ewResult->metadata['health_score'] ?? 0;
        if ($waveHealth < 50) {
            $adjustedPct -= 15;
            $adjustments[] = "Low EW health {$waveHealth}/100 (-15%)";
        }

        // -20 if only 1 engine agrees
        if ($agreeingEngines <= 1) {
            $adjustedPct -= 20;
            $adjustments[] = "Only {$agreeingEngines} engine agrees (-20%)";
        }

        // +10 if 4+ engines agree
        if ($agreeingEngines >= 4) {
            $adjustedPct += 10;
            $adjustments[] = "{$agreeingEngines} engines agree (+10%)";
        }

        // -10 if in late cycle (wave 5 or C)
        $currentWave = $ewResult->metadata['current_wave'] ?? null;
        if (in_array($currentWave, ['5', 'C'], true)) {
            $adjustedPct -= 10;
            $adjustments[] = "Late cycle wave {$currentWave} (-10%)";
        }

        // Cap: 30% floor, 85% ceiling
        $adjustedPct = max(30, min(85, (int) round($adjustedPct)));

        // ── Phase 6: Call/Put + Action determination ──
        $callPut = $this->determineCallPut($direction, $htfBias, $adjustedPct, $gatesOk, $conflict);
        $action = $this->determineAction($total, $direction, $contextScore, $levelsScore, $triggerScore, $gatesOk, $conflict, $htfBias);
        $userReason = $this->buildUserReason($callPut, $direction, $htfBias, $adjustedPct, $gatesOk, $conflict, $agreeingEngines, $currentWave);
        $marketTrend = $this->buildMarketTrend($htfBias, $direction, $conflict);

        return [
            'total_score' => $total,
            'max_score' => $maxTotal,
            'pct' => (int) $basePct,
            'adjustedPct' => $adjustedPct,
            'direction' => $direction,
            'callPut' => $callPut,
            'action' => $action,
            'userReason' => $userReason,
            'marketTrend' => $marketTrend,
            'computed_at' => now()->toIso8601String(),
            'conflict' => $conflict,
            'conflictNote' => $conflictNote,
            'htfBias' => $htfBias,
            'adjustments' => $adjustments,
            'breakdown' => [
                'context' => [
                    'score' => $contextScore['score'],
                    'max' => 35,
                    'ok' => $contextScore['score'] >= 20,
                    'desc' => $contextScore['desc'],
                    'details' => $contextScore['details'],
                ],
                'levels' => [
                    'score' => $levelsScore['score'],
                    'max' => 35,
                    'ok' => $levelsScore['score'] >= 20,
                    'desc' => $levelsScore['desc'],
                    'details' => $levelsScore['details'],
                ],
                'trigger' => [
                    'score' => $triggerScore['score'],
                    'max' => 30,
                    'ok' => $triggerScore['score'] >= 15,
                    'desc' => $triggerScore['desc'],
                    'details' => $triggerScore['details'],
                ],
            ],
            'gates' => $gateChecks,
            'gatesPassed' => $gatesPassed,
            'gatesOk' => $gatesOk,
            'bull_signals' => $rawBullCount,
            'bear_signals' => $rawBearCount,
            'weighted_bull' => round($weightedBull, 2),
            'weighted_bear' => round($weightedBear, 2),
            'engines_agreeing' => $agreeingEngines,
        ];
    }

    /**
     * Determine BUY CALL / BUY PUT / WAIT recommendation.
     * Simplified for basic options traders — no HEDGE, no complex states.
     * System handles trend conflict internally; user sees only the final answer.
     */
    private function determineCallPut(
        string $direction,
        string $htfBias,
        int $adjustedPct,
        bool $gatesOk,
        bool $conflict,
    ): string {
        // Gate check: if minimum conditions not met, always WAIT
        if (! $gatesOk) {
            return 'WAIT';
        }

        // Below 40% confidence: WAIT (too uncertain for any trade)
        if ($adjustedPct < 40) {
            return 'WAIT';
        }

        // Conflict scenarios: signal opposes HTF trend
        if ($conflict) {
            // Only allow counter-trend trade if very strong signal (≥65%)
            // Otherwise WAIT — don't confuse basic users with hedge concepts
            if ($adjustedPct < 65) {
                return 'WAIT';
            }
            // Strong counter-trend signal: follow the signal direction
            return $direction === 'BULL' ? 'BUY CALL' : 'BUY PUT';
        }

        // Aligned or neutral HTF: follow signal direction
        if ($direction === 'BULL' && $adjustedPct >= 50) {
            return 'BUY CALL';
        }

        if ($direction === 'BEAR' && $adjustedPct >= 50) {
            return 'BUY PUT';
        }

        return 'WAIT';
    }

    /**
     * Action recommendation for the bottom card.
     * Now unified with confluence direction + HTF bias.
     */
    private function determineAction(
        int $total,
        string $direction,
        array $context,
        array $levels,
        array $trigger,
        bool $gatesOk,
        bool $conflict,
        string $htfBias,
    ): string {
        // If gates not met, no action
        if (! $gatesOk) {
            if ($trigger['score'] < 10) {
                return 'WAIT FOR BOS';
            }
            if ($context['score'] < 20) {
                return 'WAIT FOR CONTEXT';
            }

            return 'NO TRADE';
        }

        // If HTF conflicts, reduce action strength
        if ($conflict) {
            if ($total >= 70) {
                return $direction === 'BULL' ? 'HEDGE BUY' : 'HEDGE SELL';
            }

            return 'WAIT — HTF CONFLICT';
        }

        // Normal action determination
        if ($total >= 80) {
            return $direction === 'BULL' ? 'STRONG BUY' : ($direction === 'BEAR' ? 'STRONG SELL' : 'WAIT');
        }
        if ($total >= 60 && $trigger['score'] >= 15) {
            return $direction === 'BULL' ? 'BUY ON OB RETEST' : ($direction === 'BEAR' ? 'SELL ON OB RETEST' : 'WAIT');
        }
        if ($total >= 40 && $context['score'] >= 20) {
            return 'WAIT FOR TRIGGER';
        }
        if ($trigger['score'] < 10) {
            return 'WAIT FOR BOS';
        }

        return 'NO TRADE';
    }

    /**
     * Build a plain-English reason for basic users.
     * No jargon — just clear direction rationale.
     */
    private function buildUserReason(
        string $callPut,
        string $direction,
        string $htfBias,
        int $adjustedPct,
        bool $gatesOk,
        bool $conflict,
        int $agreeingEngines,
        ?string $currentWave,
    ): string {
        if ($callPut === 'WAIT') {
            if (! $gatesOk) {
                return 'Not enough signals to take a trade right now. Wait for a clearer setup.';
            }
            if ($conflict && $adjustedPct < 65) {
                return 'Market trend and short-term signals disagree. Stay on the sideline until they align.';
            }
            if ($adjustedPct < 40) {
                return 'Signals are too weak. No clear direction to trade.';
            }
            if ($direction === 'NEUTRAL') {
                return 'Market is sideways. Wait for a breakout in either direction.';
            }

            return 'Conditions are not strong enough for a confident trade.';
        }

        $strength = $adjustedPct >= 70 ? 'Strong' : ($adjustedPct >= 55 ? 'Moderate' : 'Early');
        $engineNote = $agreeingEngines >= 4 ? 'Multiple indicators agree.' : ($agreeingEngines >= 2 ? 'Key indicators align.' : '');

        if ($callPut === 'BUY CALL') {
            $trendNote = $htfBias === 'BULL'
                ? 'Market trend is UP.'
                : ($conflict ? 'Short-term bounce detected against the trend.' : 'Upward momentum building.');
            $waveNote = in_array($currentWave, ['1', '3'], true) ? ' Strong wave phase.' : '';

            return "{$strength} bullish signal. {$trendNote}{$waveNote} {$engineNote}";
        }

        if ($callPut === 'BUY PUT') {
            $trendNote = $htfBias === 'BEAR'
                ? 'Market trend is DOWN.'
                : ($conflict ? 'Short-term dip detected against the trend.' : 'Downward pressure building.');
            $waveNote = in_array($currentWave, ['3', 'C'], true) ? ' Strong wave phase.' : '';

            return "{$strength} bearish signal. {$trendNote}{$waveNote} {$engineNote}";
        }

        return 'Analyzing market conditions...';
    }

    /**
     * Build simplified market trend summary for basic users.
     * Returns: label, direction, strength
     */
    private function buildMarketTrend(string $htfBias, string $direction, bool $conflict): array
    {
        if ($htfBias === 'BULL') {
            $label = $conflict ? 'Uptrend (pullback)' : 'Uptrend';
            $emoji = '📈';
        } elseif ($htfBias === 'BEAR') {
            $label = $conflict ? 'Downtrend (bounce)' : 'Downtrend';
            $emoji = '📉';
        } else {
            $label = 'Sideways';
            $emoji = '↔️';
        }

        return [
            'label' => $label,
            'emoji' => $emoji,
            'direction' => $htfBias,
            'conflict' => $conflict,
        ];
    }

    /**
     * Context score: Elliott Wave position + Market Structure trend (max 35).
     */
    private function scoreContext(EngineResult $ew, EngineResult $ms): array
    {
        $score = 0;
        $details = [];

        // Elliott Wave health (0-15 points)
        $ewHealth = $ew->metadata['health_score'] ?? 0;
        $ewPoints = min(15, (int) ($ewHealth / 100 * 15));
        $score += $ewPoints;
        $details[] = "EW health: {$ewHealth}/100 (+{$ewPoints})";

        // Current wave position (0-10 points)
        $currentWave = $ew->metadata['current_wave'] ?? null;
        if ($currentWave) {
            $wavePoints = match ($currentWave) {
                '3' => 10,    // Wave 3 = strongest trend
                '1' => 8,     // Wave 1 = trend start
                '5' => 7,     // Wave 5 = late trend
                'C' => 6,     // Wave C = correction ending
                '2', '4' => 5, // Corrective = pullback opportunity
                'A', 'B' => 3,
                default => 2,
            };
            $score += $wavePoints;
            $details[] = "Wave {$currentWave} (+{$wavePoints})";
        }

        // Market structure trend clarity (0-10 points)
        $trend = $ms->metadata['trend'] ?? 'neutral';
        $bosCount = $ms->metadata['bos_count'] ?? 0;
        $trendPoints = $trend !== 'neutral' ? min(10, 5 + min(5, $bosCount)) : 2;
        $score += $trendPoints;
        $details[] = "Trend: {$trend}, {$bosCount} BOS (+{$trendPoints})";

        $degree = $ew->metadata['degree'] ?? '';
        $desc = $currentWave
            ? "{$degree} wave {$currentWave}"
            : "{$trend} trend";

        return ['score' => min(35, $score), 'desc' => $desc, 'details' => $details];
    }

    /**
     * Levels score: OB + FVG + OTE + VWAP alignment (max 35).
     */
    private function scoreLevels(EngineResult $ob, EngineResult $fvg, EngineResult $smc, EngineResult $vwap, float $price): array
    {
        $score = 0;
        $details = [];

        // Fresh Order Blocks near price (0-10 points)
        $freshObs = 0;
        foreach ($ob->overlays['orderBlocks'] ?? [] as $block) {
            if (($block['status'] ?? '') === 'fresh') {
                $obMid = ($block['high'] + $block['low']) / 2;
                $dist = abs($price - $obMid) / $price * 100;
                if ($dist < 2) {
                    $freshObs++;
                }
            }
        }
        $obPoints = min(10, $freshObs * 5);
        $score += $obPoints;
        if ($freshObs > 0) {
            $details[] = "{$freshObs} fresh OB near price (+{$obPoints})";
        }

        // Unfilled FVG near price (0-8 points)
        $nearFvg = 0;
        foreach ($fvg->overlays['fvgs'] ?? [] as $gap) {
            if (($gap['fill_pct'] ?? 0) < 50) {
                $fvgMid = ($gap['high'] + $gap['low']) / 2;
                $dist = abs($price - $fvgMid) / $price * 100;
                if ($dist < 3) {
                    $nearFvg++;
                }
            }
        }
        $fvgPoints = min(8, $nearFvg * 4);
        $score += $fvgPoints;
        if ($nearFvg > 0) {
            $details[] = "{$nearFvg} FVG near price (+{$fvgPoints})";
        }

        // OTE zone alignment (0-10 points)
        $inOTE = false;
        foreach ($smc->overlays['oteZones'] ?? [] as $ote) {
            if ($price >= $ote['low'] && $price <= $ote['high']) {
                $inOTE = true;
                break;
            }
        }
        if ($inOTE) {
            $score += 10;
            $details[] = 'In OTE zone (+10)';
        }

        // VWAP alignment (0-7 points)
        $vwapData = $vwap->overlays['vwap'] ?? [];
        if (! empty($vwapData)) {
            $lastVwap = end($vwapData);
            $vwapPrice = $lastVwap['vwap'] ?? 0;
            $vwapDist = abs($price - $vwapPrice) / $price * 100;
            if ($vwapDist < 0.5) {
                $score += 7;
                $details[] = 'Near VWAP (+7)';
            } elseif ($vwapDist < 1) {
                $score += 4;
                $details[] = 'Within 1% of VWAP (+4)';
            }
        }

        $desc = collect(array_filter([
            $freshObs > 0 ? 'OB' : null,
            $nearFvg > 0 ? 'FVG' : null,
            $inOTE ? '0.618' : null,
        ]))->join(' + ') ?: 'No levels';

        return ['score' => min(35, $score), 'desc' => $desc, 'details' => $details];
    }

    /**
     * Trigger score: BOS/CHOCH confirmation + price action pattern (max 30).
     */
    private function scoreTrigger(EngineResult $ms, EngineResult $pa): array
    {
        $score = 0;
        $details = [];

        // Recent BOS/CHOCH (0-15 points)
        $bos = $ms->overlays['bos'] ?? [];
        $recentBos = array_slice($bos, -3);
        $hasChoch = false;

        if (! empty($recentBos)) {
            foreach ($recentBos as $b) {
                if (($b['type'] ?? '') === 'choch') {
                    $hasChoch = true;
                }
            }

            if ($hasChoch) {
                $score += 15;
                $details[] = 'CHOCH confirmed (+15)';
            } else {
                $score += 10;
                $details[] = 'BOS confirmed (+10)';
            }
        } else {
            $details[] = 'No BOS/CHOCH';
        }

        // Price action pattern (0-15 points)
        $patterns = $pa->overlays['patterns'] ?? [];
        $recentPatterns = array_slice($patterns, -5);
        $strongPatterns = array_filter($recentPatterns, fn ($p) => ($p['direction'] ?? '') !== 'neutral');

        if (count($strongPatterns) >= 2) {
            $score += 15;
            $details[] = count($strongPatterns) . ' patterns (+15)';
        } elseif (count($strongPatterns) === 1) {
            $score += 8;
            $p = reset($strongPatterns);
            $details[] = ($p['pattern'] ?? 'pattern') . ' (+8)';
        } else {
            $details[] = 'No trigger pattern';
        }

        $desc = empty($recentBos) ? 'Wait BOS' : ($hasChoch ? 'CHOCH + pattern' : 'BOS confirmed');

        return ['score' => min(30, $score), 'desc' => $desc, 'details' => $details];
    }
}
