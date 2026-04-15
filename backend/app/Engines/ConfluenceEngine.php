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
     * @param  string  $htfBias   'BULL'|'BEAR'|'NEUTRAL' — aggregate MTF bias
     * @param  string  $timeframe Active timeframe being analyzed
     * @param  array<string,string>  $htfTrends  Per-TF trend map
     *         ('1M'|'5M'|...  => 'BULL'|'BEAR'|'NEUTRAL') used by the
     *         per-TF HTF alignment check. Must contain entries for every
     *         timeframe strictly higher than the active one, otherwise the
     *         "all-HTFs agree" bonus is skipped.
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
        array $htfTrends = [],
    ): array {
        // Wave-state gate — invalidated counts must never drive direction.
        $waveState  = $ewResult->metadata['wave_state'] ?? 'confirmed';
        $waveHealth = (int) ($ewResult->metadata['health_score'] ?? 0);
        $ewConfirmed = ($waveState === 'confirmed' && $waveHealth >= 70);
        $ewInvalidated = ($waveState === 'invalidated');
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
        $agreeingEngines = 0;
        $allDirectionalSignals = []; // Collect for price-zone analysis

        foreach ($engineResults as $engineKey => $result) {
            // Invalidated Elliott Wave counts must not vote on direction —
            // their signals encode the broken chain and would flip the bias.
            if ($engineKey === 'elliott_wave' && $ewInvalidated) {
                continue;
            }

            $engineWeight = self::ENGINE_WEIGHTS[$engineKey] ?? 1.0;
            $engineBull = 0;
            $engineBear = 0;

            foreach ($result->signals as $signal) {
                $dir = $signal['direction'] ?? '';

                // Signal recency weighting: signals with a recent candle_timestamp
                // get 2x count vs older signals (if timestamp is available)
                $recencyMultiplier = 1;
                if (isset($signal['candle_timestamp'])) {
                    try {
                        $signalAge = now()->diffInMinutes(\Carbon\Carbon::parse($signal['candle_timestamp']));
                        // "Recent" = within last 5 candle periods (rough heuristic)
                        $recencyMultiplier = $signalAge < 30 ? 2 : 1;
                    } catch (\Throwable $e) {
                        // Ignore parse errors
                    }
                }

                if ($dir === 'buy') {
                    $engineBull += $recencyMultiplier;
                    $rawBullCount++;
                }
                if ($dir === 'sell') {
                    $engineBear += $recencyMultiplier;
                    $rawBearCount++;
                }

                // Collect for price-zone clustering
                if ($dir === 'buy' || $dir === 'sell') {
                    $allDirectionalSignals[] = [
                        'engine' => $engineKey,
                        'direction' => $dir,
                        'price' => (float) ($signal['entry'] ?? 0),
                    ];
                }
            }

            if ($engineBull > $engineBear) {
                $weightedBull += $engineWeight;
                $agreeingEngines++;
            } elseif ($engineBear > $engineBull) {
                $weightedBear += $engineWeight;
                $agreeingEngines++;
            }
        }

        // ── Phase 2b: Price-zone clustering ──
        // Check how many DIFFERENT engines have signals within 0.5% of current price
        $zoneEngines = [];
        if ($currentPrice > 0) {
            $zoneTolerance = $currentPrice * 0.005; // 0.5%
            foreach ($allDirectionalSignals as $sig) {
                if ($sig['price'] > 0 && abs($sig['price'] - $currentPrice) <= $zoneTolerance) {
                    $zoneEngines[$sig['engine']] = true;
                }
            }
        }
        $zoneAgreement = count($zoneEngines);

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
            'zone_agree' => $zoneAgreement >= 2,                 // 2+ engines at same price zone
        ];

        $gatesPassed = count(array_filter($gateChecks));
        $gateTotalRequired = 3; // need at least 3 of 5 gates to show directional signal
        $gatesOk = $gatesPassed >= $gateTotalRequired;

        // ── Phase 5: Dynamic Confidence Adjustments (Layer 5) ──
        $basePct = $maxTotal > 0 ? round($total / $maxTotal * 100) : 0;
        $adjustments = [];
        $adjustedPct = (float) $basePct;

        // ── Per-TF HTF alignment (Fix 3) ──
        // The legacy "+10% if aggregate htfBias matches" hid the case where
        // 4H and 1H are BULL but a noisy 1D vote pushes aggregate to BEAR.
        // Now we check EVERY timeframe strictly above the active one.
        $tfOrder = ['1M' => 1, '5M' => 2, '15M' => 3, '1H' => 4, '4H' => 5, '1D' => 6];
        $activeRank = $tfOrder[$timeframe] ?? 0;
        $htfAbove = [];
        foreach ($htfTrends as $tf => $bias) {
            if (($tfOrder[$tf] ?? 0) > $activeRank) {
                $htfAbove[$tf] = strtoupper((string) $bias);
            }
        }

        if (! empty($htfAbove) && $direction !== 'NEUTRAL') {
            $agreeCount = 0;
            $disagreeCount = 0;
            foreach ($htfAbove as $bias) {
                if ($bias === $direction) {
                    $agreeCount++;
                } elseif ($bias !== 'NEUTRAL') {
                    $disagreeCount++;
                }
            }
            $total = count($htfAbove);
            if ($agreeCount === $total) {
                $adjustedPct += 10;
                $adjustments[] = "All {$total} HTFs aligned (+10%)";
            } elseif ($disagreeCount >= 2) {
                $adjustedPct -= 10;
                $adjustments[] = "{$disagreeCount} HTFs disagree (-10%)";
            } elseif ($agreeCount > $disagreeCount) {
                // Partial agreement still gets a small nudge
                $adjustedPct += 4;
                $adjustments[] = "{$agreeCount}/{$total} HTFs aligned (+4%)";
            }
        } elseif ($htfBias !== 'NEUTRAL' && $htfBias === $direction) {
            // Fallback to aggregate bias when per-TF map unavailable
            $adjustedPct += 10;
            $adjustments[] = 'HTF aligned (+10%)';
        }

        // -25 if aggregate HTF conflicts with signal
        if ($conflict) {
            $adjustedPct -= 25;
            $adjustments[] = 'HTF conflict (-25%)';
        }

        // +8 if all 3 level types present AND each is still valid at the
        // current close (same re-validation as scoreLevels).
        $hasOb = ! empty(array_filter(
            $obResult->overlays['orderBlocks'] ?? [],
            function ($b) use ($currentPrice) {
                if (($b['status'] ?? '') !== 'fresh') return false;
                if ($b['type'] === 'bullish' && $currentPrice < ($b['low'] ?? 0)) return false;
                if ($b['type'] === 'bearish' && $currentPrice > ($b['high'] ?? PHP_FLOAT_MAX)) return false;
                return true;
            }
        ));
        $hasFvg = ! empty(array_filter(
            $fvgResult->overlays['fvgs'] ?? [],
            function ($f) use ($currentPrice) {
                if ((float) ($f['fill_pct'] ?? 100) >= 25) return false;
                if ($f['type'] === 'bullish' && $currentPrice < ($f['low'] ?? 0)) return false;
                if ($f['type'] === 'bearish' && $currentPrice > ($f['high'] ?? PHP_FLOAT_MAX)) return false;
                return true;
            }
        ));
        $hasOte = ! empty(array_filter(
            $smcResult->overlays['oteZones'] ?? [],
            fn ($o) => $currentPrice >= ($o['low'] ?? 0) && $currentPrice <= ($o['high'] ?? PHP_FLOAT_MAX)
        ));
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

        // +12 if 3+ engines agree at the same price zone (strong confluence)
        if ($zoneAgreement >= 3) {
            $adjustedPct += 12;
            $adjustments[] = "{$zoneAgreement} engines at same price zone (+12%)";
        } elseif ($zoneAgreement >= 2) {
            $adjustedPct += 6;
            $adjustments[] = "{$zoneAgreement} engines at same price zone (+6%)";
        }

        // -15 if no engines agree at the same price zone
        if ($zoneAgreement === 0 && $agreeingEngines >= 2) {
            $adjustedPct -= 15;
            $adjustments[] = 'No price-zone agreement (-15%)';
        }

        // Confidence adjustment based on wave position.
        // Only honour wave-position bonuses/penalties when the count is
        // confirmed AND health is adequate — otherwise we'd be scoring a
        // Wave 2 pullback that doesn't actually exist.
        $currentWave = $ewResult->metadata['current_wave'] ?? null;
        if ($ewConfirmed && $currentWave) {
            if (in_array($currentWave, ['5', 'C'], true)) {
                // Late cycle: trend exhaustion — reduce confidence
                $adjustedPct -= 10;
                $adjustments[] = "Late cycle wave {$currentWave} (-10%)";
            } elseif ($currentWave === '3') {
                // Wave 3 is the strongest impulse — boost confidence
                $adjustedPct += 5;
                $adjustments[] = 'Strong impulse wave 3 (+5%)';
            }
        } elseif ($currentWave && $waveState === 'awaiting_confirmation') {
            $adjustedPct -= 5;
            $adjustments[] = "EW count awaiting confirmation (-5%)";
        } elseif ($ewInvalidated) {
            $adjustedPct -= 15;
            $adjustments[] = 'EW count invalidated — rules broken (-15%)';
        }

        // ── Phase 5b: Wave-position direction override ──
        // When EW engine has a clear wave label, use it to determine the
        // correct CALL/PUT direction per Elliott Wave theory:
        //   Uptrend impulse: 1,3,5=BULL  2,4=BEAR  A,C=BEAR  B=BULL
        //   Downtrend impulse: inverted
        // The EW engine's signal direction already encodes this logic, so
        // extract it directly from the EW signals when wave health is adequate.
        //
        // Fallback: if metadata current_wave is null (cache staleness), derive
        // it from waveLabels in the overlay data.
        if (! $currentWave) {
            $waveLabels = $ewResult->overlays['waveLabels'] ?? [];
            if (! empty($waveLabels)) {
                $lastLabel = end($waveLabels);
                $currentWave = $lastLabel['label'] ?? null;
            }
        }

        // Wave-position direction override — only trust it when the wave
        // count is CONFIRMED and health ≥ 70. Previously this fired at
        // health ≥ 40 which let a broken chain drive BUY CALL/PUT. When
        // the count is invalidated or awaiting_confirmation, fall through
        // to the aggregate engine direction instead.
        $ewDirection = null;
        if ($ewConfirmed && $currentWave) {
            $ewSignals = $ewResult->signals;
            if (! empty($ewSignals)) {
                // Primary signal (first one) carries the wave-position direction
                $primaryDir = $ewSignals[0]['direction'] ?? null;
                if ($primaryDir === 'buy') {
                    $ewDirection = 'BULL';
                } elseif ($primaryDir === 'sell') {
                    $ewDirection = 'BEAR';
                }
            }
        }

        // Cap: 30% floor, 85% ceiling
        $adjustedPct = max(30, min(85, (int) round($adjustedPct)));

        // ── Phase 6: Call/Put + Action determination ──
        // Use wave-position direction when available and health is adequate;
        // fall back to aggregate engine direction otherwise.
        $callPutDirection = $ewDirection ?? $direction;

        // ── EW-invalidated safety valve ──
        // When the Elliott Wave count is broken, the aggregate engine vote
        // is often near-parity (e.g. 94 bull vs 93 bear) and one trend
        // engine tips it one way. Refuse to emit CALL/PUT in that case
        // unless there is strong, dominant agreement:
        //   • adjustedPct ≥ 70
        //   • at least 3 engines agree on the direction
        //   • weighted direction margin ≥ 2× (e.g. 6 vs 3, not 3 vs 2)
        // Otherwise force WAIT — a broken wave count + weak margin is
        // exactly the scenario the user reported ("chart is clearly up,
        // system says BUY PUT").
        if ($ewInvalidated) {
            $winnerWeight = max($weightedBull, $weightedBear);
            $loserWeight = min($weightedBull, $weightedBear);
            $dominantMargin = $loserWeight > 0
                ? ($winnerWeight / $loserWeight) >= 2.0
                : $winnerWeight >= 3;
            $strongAgreement = $agreeingEngines >= 3;

            if ($adjustedPct < 70 || ! $strongAgreement || ! $dominantMargin) {
                $adjustments[] = 'EW invalidated + weak margin → forced WAIT';
                $callPutDirection = 'NEUTRAL';
            }
        }

        $callPut = $this->determineCallPut($callPutDirection, $htfBias, $adjustedPct, $gatesOk, $conflict);
        $action = $this->determineAction($total, $direction, $contextScore, $levelsScore, $triggerScore, $gatesOk, $conflict, $htfBias);
        $userReason = $this->buildUserReason($callPut, $direction, $htfBias, $adjustedPct, $gatesOk, $conflict, $agreeingEngines, $currentWave);
        // Build the market-trend label from the ACTIVE timeframe's own
        // structure (the chart the user is looking at), not from the HTF
        // aggregate. Previously a bullish 5M would show "Downtrend (bounce)"
        // just because 1D+4H were bearish — confusing and wrong for the
        // chart the user has open. HTF context is still surfaced via the
        // conflict flag / conflictNote / htfBias fields.
        $activeTrend = strtoupper((string) ($msResult->metadata['trend'] ?? 'neutral'));
        if ($activeTrend === 'BULLISH') {
            $activeTrend = 'BULL';
        } elseif ($activeTrend === 'BEARISH') {
            $activeTrend = 'BEAR';
        } else {
            $activeTrend = 'NEUTRAL';
        }
        $marketTrend = $this->buildMarketTrend($activeTrend, $direction, $conflict, $htfBias);

        return [
            'total_score' => $total,
            'max_score' => $maxTotal,
            'pct' => (int) $basePct,
            'adjustedPct' => $adjustedPct,
            'direction' => $callPutDirection,
            'currentWave' => $currentWave,
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
            'zone_agreement' => $zoneAgreement,
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

        // Wave-position context for user-facing reason
        $waveContext = match ($currentWave) {
            '1' => 'Wave 1 — new trend starting.',
            '2' => 'Wave 2 — pullback entry zone.',
            '3' => 'Wave 3 — strongest impulse.',
            '4' => 'Wave 4 — correction pullback.',
            '5' => 'Wave 5 — late trend push.',
            'A' => 'Wave A — correction underway.',
            'B' => 'Wave B — counter-trend bounce.',
            'C' => 'Wave C — final correction leg.',
            default => '',
        };

        if ($callPut === 'BUY CALL') {
            $trendNote = $htfBias === 'BULL'
                ? 'Market trend is UP.'
                : ($conflict ? 'Short-term bounce detected against the trend.' : 'Upward momentum building.');

            return "{$strength} bullish signal. {$trendNote} {$waveContext} {$engineNote}";
        }

        if ($callPut === 'BUY PUT') {
            $trendNote = $htfBias === 'BEAR'
                ? 'Market trend is DOWN.'
                : ($conflict ? 'Short-term dip detected against the trend.' : 'Downward pressure building.');

            return "{$strength} bearish signal. {$trendNote} {$waveContext} {$engineNote}";
        }

        return 'Analyzing market conditions...';
    }

    /**
     * Build simplified market trend summary for basic users.
     * Returns: label, direction, strength
     */
    /**
     * Build the market-trend pill shown at the bottom of the chart.
     *
     * @param string $activeTrend BULL|BEAR|NEUTRAL — the trend of the CHART
     *                            currently being viewed (from its own
     *                            MarketStructure engine).
     * @param string $direction   Raw weighted engine vote (BULL|BEAR|NEUTRAL).
     * @param bool   $conflict    True when HTF bias opposes $direction.
     * @param string $htfBias     HTF aggregate bias, used only to annotate
     *                            the label when it contradicts the active TF.
     */
    private function buildMarketTrend(string $activeTrend, string $direction, bool $conflict, string $htfBias = 'NEUTRAL'): array
    {
        // HTF disagrees with the local chart trend → show an annotation so
        // the user knows to be cautious, but keep the label matched to what
        // they're actually seeing on the chart.
        $htfOpposes = $htfBias !== 'NEUTRAL' && $activeTrend !== 'NEUTRAL' && $htfBias !== $activeTrend;

        if ($activeTrend === 'BULL') {
            $label = $htfOpposes ? 'Uptrend (HTF bearish)' : 'Uptrend';
            $emoji = '📈';
        } elseif ($activeTrend === 'BEAR') {
            $label = $htfOpposes ? 'Downtrend (HTF bullish)' : 'Downtrend';
            $emoji = '📉';
        } else {
            $label = 'Sideways';
            $emoji = '↔️';
        }

        return [
            'label' => $label,
            'emoji' => $emoji,
            'direction' => $activeTrend,
            'conflict' => $conflict,
            'htfBias' => $htfBias,
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

        // Current wave position (0-10 points) — only awarded when the
        // count is not invalidated; otherwise current_wave is null anyway.
        $waveState = $ew->metadata['wave_state'] ?? 'confirmed';
        $currentWave = $ew->metadata['current_wave'] ?? null;
        if ($waveState === 'invalidated') {
            $currentWave = null;
        }
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

        // Market structure trend clarity (0-10 points) — scored from the
        // NET recent BOS count only. Cumulative BOS counts over months
        // used to let a stale bear streak dominate a live bull trend.
        $trend = $ms->metadata['trend'] ?? 'neutral';
        $bosCount = $ms->metadata['net_recent_bos']
            ?? $ms->metadata['bos_count']
            ?? 0;
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
     *
     * Every zone is re-validated against $price before it is counted:
     *   • Bullish OB invalidated if price < ob['low']
     *   • Bearish OB invalidated if price > ob['high']
     *   • FVG required to be <25% filled (was 50%) and untouched on the
     *     wrong side — stale zones price has blown past don't count.
     *   • OTE zone must contain price AND retain its impulse direction.
     */
    private function scoreLevels(EngineResult $ob, EngineResult $fvg, EngineResult $smc, EngineResult $vwap, float $price): array
    {
        $score = 0;
        $details = [];

        // Fresh Order Blocks near price (0-10 points)
        $freshObs = 0;
        foreach ($ob->overlays['orderBlocks'] ?? [] as $block) {
            if (($block['status'] ?? '') !== 'fresh') {
                continue;
            }
            // Re-validate against the current close — a "fresh" bullish OB
            // whose low has been decisively broken is actually invalidated.
            if ($block['type'] === 'bullish' && $price < ($block['low'] ?? 0)) {
                continue;
            }
            if ($block['type'] === 'bearish' && $price > ($block['high'] ?? PHP_FLOAT_MAX)) {
                continue;
            }
            $obMid = ($block['high'] + $block['low']) / 2;
            $dist = abs($price - $obMid) / max($price, 1e-9) * 100;
            if ($dist < 2) {
                $freshObs++;
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
            $fillPct = (float) ($gap['fill_pct'] ?? 0);
            if ($fillPct >= 25) {
                continue; // tightened from 50% — >25% filled = stale
            }
            // Invalidate FVGs the price has blown past entirely.
            if ($gap['type'] === 'bullish' && $price < ($gap['low'] ?? 0)) {
                continue;
            }
            if ($gap['type'] === 'bearish' && $price > ($gap['high'] ?? PHP_FLOAT_MAX)) {
                continue;
            }
            $fvgMid = ($gap['high'] + $gap['low']) / 2;
            $dist = abs($price - $fvgMid) / max($price, 1e-9) * 100;
            if ($dist < 3) {
                $nearFvg++;
            }
        }
        $fvgPoints = min(8, $nearFvg * 4);
        $score += $fvgPoints;
        if ($nearFvg > 0) {
            $details[] = "{$nearFvg} FVG near price (+{$fvgPoints})";
        }

        // OTE zone alignment (0-10 points) — only if price is INSIDE the
        // zone right now. A drawn OTE box that price has walked out of
        // must not keep contributing to levels score.
        $inOTE = false;
        foreach ($smc->overlays['oteZones'] ?? [] as $ote) {
            if ($price >= ($ote['low'] ?? 0) && $price <= ($ote['high'] ?? PHP_FLOAT_MAX)) {
                // Re-validate direction: bullish OTE requires price still
                // above impulse_start; bearish requires still below.
                if (($ote['type'] ?? '') === 'bullish' && $price < ($ote['impulse_start'] ?? 0)) {
                    continue;
                }
                if (($ote['type'] ?? '') === 'bearish' && $price > ($ote['impulse_start'] ?? PHP_FLOAT_MAX)) {
                    continue;
                }
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
