<?php

declare(strict_types=1);

namespace App\Engines;

class ConfluenceEngine
{
    /**
     * Score confluence across all engine results for a given price level.
     * Returns 0-100 composite score with breakdown.
     *
     * Scoring weights:
     * - Context (HTF wave position):      max 35 points
     * - Levels (OB + FVG + OTE alignment): max 35 points
     * - Trigger (BOS/CHOCH + pattern):     max 30 points
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
    ): array {
        $contextScore = $this->scoreContext($ewResult, $msResult);
        $levelsScore = $this->scoreLevels($obResult, $fvgResult, $smcResult, $vwapResult, $currentPrice);
        $triggerScore = $this->scoreTrigger($msResult, $paResult);

        $total = $contextScore['score'] + $levelsScore['score'] + $triggerScore['score'];
        $maxTotal = 35 + 35 + 30;

        // Determine direction bias
        $bullSignals = 0;
        $bearSignals = 0;
        foreach ([$ewResult, $msResult, $obResult, $fvgResult, $smcResult, $paResult] as $result) {
            foreach ($result->signals as $signal) {
                if (($signal['direction'] ?? '') === 'buy') {
                    $bullSignals++;
                }
                if (($signal['direction'] ?? '') === 'sell') {
                    $bearSignals++;
                }
            }
        }

        $direction = $bullSignals > $bearSignals ? 'BULL' : ($bearSignals > $bullSignals ? 'BEAR' : 'NEUTRAL');

        // Action recommendation
        $action = $this->determineAction($total, $direction, $contextScore, $levelsScore, $triggerScore);

        return [
            'total_score' => $total,
            'max_score' => $maxTotal,
            'pct' => $maxTotal > 0 ? round($total / $maxTotal * 100) : 0,
            'direction' => $direction,
            'action' => $action,
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
            'bull_signals' => $bullSignals,
            'bear_signals' => $bearSignals,
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
                '5' => 7,     // Wave 5 = late trend
                '1' => 8,     // Wave 1 = trend start
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

        $phase = $ew->metadata['phase'] ?? 'unknown';
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
            if ($block['status'] === 'fresh') {
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

        // Premium/discount zone
        $pd = $smc->overlays['premiumDiscount'] ?? [];
        $zone = $pd['currentZone'] ?? 'equilibrium';

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
        if (! empty($recentBos)) {
            $lastBos = end($recentBos);
            $hasChoch = false;
            foreach ($recentBos as $b) {
                if ($b['type'] === 'choch') {
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

        $desc = empty($recentBos) ? 'Wait BOS' : (isset($hasChoch) && $hasChoch ? 'CHOCH + pattern' : 'BOS confirmed');

        return ['score' => min(30, $score), 'desc' => $desc, 'details' => $details];
    }

    private function determineAction(int $total, string $direction, array $context, array $levels, array $trigger): string
    {
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
}
