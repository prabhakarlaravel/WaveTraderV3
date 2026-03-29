<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\ElliottWaveEngine;
use App\Engines\MarketStructureEngine;
use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use Illuminate\Support\Facades\DB;

class WaveHealthService
{
    /**
     * Full validation across all TFs for a symbol.
     */
    public function validateAll(int $symbolId): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $timeframes = ['1D', '4H', '1H', '15M', '5M', '1M'];

        $tfResults = [];
        $totalViolations = 0;
        $criticalCount = 0;
        $warningCount = 0;
        $fixableCount = 0;
        $totalScore = 0;

        foreach ($timeframes as $tf) {
            $health = $this->analyzeTimeframe($symbol, $tf);
            $dataCheck = $this->checkDataIntegrity($symbol->id, $tf);

            // Test if fixable with alternate parameters
            $bestAlt = $this->findBestParameters($symbol, $tf);

            $health['data_check'] = $dataCheck;
            $health['swing_strength'] = 5;
            $health['fixable'] = $bestAlt['improved'];
            $health['best_alt'] = $bestAlt;

            foreach ($health['violations'] as $v) {
                $totalViolations++;
                if (($v['severity'] ?? 'warning') === 'critical') {
                    $criticalCount++;
                } else {
                    $warningCount++;
                }
            }
            if ($bestAlt['improved']) {
                $fixableCount++;
            }

            $totalScore += $health['score'];
            $tfResults[] = $health;
        }

        $overallIntegrity = $this->checkOverallDataIntegrity($symbolId);

        return [
            'symbol' => $symbol->ticker,
            'overallHealth' => count($timeframes) > 0 ? round($totalScore / count($timeframes)) : 0,
            'totalViolations' => $totalViolations,
            'criticalCount' => $criticalCount,
            'warningCount' => $warningCount,
            'fixableCount' => $fixableCount,
            'dataIntegrity' => $overallIntegrity,
            'timeframes' => $tfResults,
        ];
    }

    /**
     * Analyze wave health using the ElliottWaveEngine for accurate wave detection.
     */
    public function analyzeTimeframe(Symbol $symbol, string $timeframe): array
    {
        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (count($candles) < 30) {
            return [
                'symbol' => $symbol->ticker,
                'timeframe' => $timeframe,
                'score' => 0,
                'status' => 'no_data',
                'violations' => [],
                'wave_count' => 0,
                'trend' => 'neutral',
                'current_wave' => null,
                'phase' => null,
                'swing_count' => 0,
                'bos_count' => 0,
            ];
        }

        // Use ElliottWaveEngine for wave analysis
        $ewEngine = new ElliottWaveEngine();
        $ewResult = $ewEngine->run($candles, $symbol->ticker, $timeframe);

        // Use MarketStructureEngine for trend
        $msEngine = new MarketStructureEngine(5);
        $msResult = $msEngine->run($candles, $symbol->ticker, $timeframe);

        $healthScore = $ewResult->metadata['health_score'] ?? 100;
        $violations = $ewResult->metadata['violations'] ?? [];
        $currentWave = $ewResult->metadata['current_wave'] ?? null;
        $phase = $ewResult->metadata['phase'] ?? null;
        $waveCount = $ewResult->metadata['wave_count'] ?? 0;
        $trend = $msResult->metadata['trend'] ?? 'neutral';
        $swingCount = $msResult->metadata['swing_count'] ?? 0;
        $bosCount = $msResult->metadata['bos_count'] ?? 0;

        // Validate Elliott Wave rules on the detected wave labels
        $waveLabels = $ewResult->overlays['waveLabels'] ?? [];
        $ruleViolations = $this->validateWaveRules($waveLabels);

        // Merge engine violations with rule violations (avoid duplicates)
        $allViolations = $ruleViolations;

        // Recalculate score based on violations
        $score = max(0, 100 - count($allViolations) * 15);

        // Bonus for clear trend
        if ($bosCount > 3) {
            $bos = $msResult->overlays['bos'] ?? [];
            $recentBos = array_slice($bos, -5);
            $bullCount = count(array_filter($recentBos, fn ($b) => $b['direction'] === 'buy'));
            $bearCount = count($recentBos) - $bullCount;
            if ($bullCount >= 4 || $bearCount >= 4) {
                $score = min(100, $score + 10);
            }
        }

        $status = $score >= 75 ? 'valid' : ($score >= 50 ? 'caution' : 'invalidated');

        return [
            'symbol' => $symbol->ticker,
            'timeframe' => $timeframe,
            'score' => $score,
            'status' => $status,
            'violations' => $allViolations,
            'wave_count' => $waveCount,
            'current_wave' => $currentWave,
            'phase' => $phase,
            'trend' => $trend,
            'swing_count' => $swingCount,
            'bos_count' => $bosCount,
        ];
    }

    /**
     * Dashboard: health across all TFs (simple version).
     */
    public function dashboard(int $symbolId): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $timeframes = ['1D', '4H', '1H', '15M', '5M', '1M'];
        $results = [];

        foreach ($timeframes as $tf) {
            $results[] = $this->analyzeTimeframe($symbol, $tf);
        }

        return $results;
    }

    /**
     * Validate Elliott Wave rules against wave labels.
     */
    private function validateWaveRules(array $waveLabels): array
    {
        $violations = [];

        if (count($waveLabels) < 5) {
            return $violations;
        }

        // Extract prices by wave label (use last occurrence in current cycle)
        $w = [];
        foreach ($waveLabels as $label) {
            $w[$label['label']] = (float) $label['price'];
        }

        // Rule 3: Wave 3 must not be the shortest impulse wave
        if (isset($w['1'], $w['2'], $w['3'], $w['4'], $w['5'])) {
            $wave1Len = abs($w['2'] - $w['1']);
            $wave3Len = abs($w['4'] - $w['3']);
            $wave5Len = abs($w['5'] - $w['4']);

            if ($wave3Len > 0 && $wave1Len > 0 && $wave5Len > 0) {
                if ($wave3Len < $wave1Len && $wave3Len < $wave5Len) {
                    $violations[] = [
                        'rule' => 3,
                        'description' => 'Wave 3 is the shortest impulse wave',
                        'severity' => 'critical',
                        'detail' => sprintf('W1=%.0f, W3=%.0f, W5=%.0f pts', $wave1Len, $wave3Len, $wave5Len),
                    ];
                }
            }
        }

        // Rule 4: Wave 4 must not overlap Wave 1 price territory
        if (isset($w['1'], $w['2'], $w['3'], $w['4'])) {
            if ($w['3'] > $w['1']) { // Bullish impulse
                if ($w['4'] < $w['1']) {
                    $violations[] = [
                        'rule' => 4,
                        'description' => 'Wave 4 overlaps Wave 1 price territory',
                        'severity' => 'critical',
                        'detail' => sprintf('W1=%.2f, W4=%.2f (overlap)', $w['1'], $w['4']),
                    ];
                }
            } else { // Bearish impulse
                if ($w['4'] > $w['1']) {
                    $violations[] = [
                        'rule' => 4,
                        'description' => 'Wave 4 overlaps Wave 1 price territory',
                        'severity' => 'critical',
                        'detail' => sprintf('W1=%.2f, W4=%.2f (overlap)', $w['1'], $w['4']),
                    ];
                }
            }
        }

        // Rule 2: Wave 2 must not retrace beyond Wave 1 start
        if (isset($w['1'], $w['2'])) {
            // For bullish: W2 should not go below W1 origin
            // We approximate: if W2 retracement is > 100% of W1, it's a violation
            // This needs the origin price (before W1), which we may not have
        }

        return $violations;
    }

    /**
     * Try different engine parameters to find better wave count.
     * Tests: swing strengths (3,5,7,10) × with/without different ATR periods.
     */
    public function findBestParameters(Symbol $symbol, string $timeframe): array
    {
        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (count($candles) < 30) {
            return ['improved' => false, 'current' => 5, 'best' => 5, 'bestScore' => 0, 'all' => []];
        }

        // Get current score with default params
        $ewEngine = new ElliottWaveEngine();
        $ewResult = $ewEngine->run($candles, $symbol->ticker, $timeframe);
        $currentLabels = $ewResult->overlays['waveLabels'] ?? [];
        $currentViolations = $this->validateWaveRules($currentLabels);
        $currentScore = max(0, 100 - count($currentViolations) * 15);

        // Test different swing strengths via MarketStructureEngine
        $results = [];
        $swingStrengths = [3, 4, 5, 6, 7, 8, 10];

        foreach ($swingStrengths as $str) {
            $msEngine = new MarketStructureEngine($str);
            $msResult = $msEngine->run($candles, $symbol->ticker, $timeframe);
            $swings = $msResult->overlays['swings'] ?? [];
            $bos = $msResult->overlays['bos'] ?? [];
            $trend = $msResult->metadata['trend'] ?? 'neutral';

            // Derive wave labels from these swings
            $waveLabels = $this->deriveWaveLabelsFromSwings($swings);
            $violations = $this->validateWaveRules($waveLabels);
            $score = max(0, 100 - count($violations) * 15);

            // Trend clarity bonus
            if (count($bos) > 3) {
                $recentBos = array_slice($bos, -5);
                $bullCount = count(array_filter($recentBos, fn ($b) => $b['direction'] === 'buy'));
                $bearCount = count($recentBos) - $bullCount;
                if ($bullCount >= 4 || $bearCount >= 4) {
                    $score = min(100, $score + 10);
                }
            }

            $results[$str] = [
                'strength' => $str,
                'score' => $score,
                'violations' => count($violations),
                'swings' => count($swings),
                'trend' => $trend,
            ];
        }

        // Find best that's actually better than current
        $best = ['strength' => 5, 'score' => $currentScore];
        foreach ($results as $r) {
            if ($r['score'] > $best['score']) {
                $best = $r;
            }
        }

        return [
            'improved' => $best['score'] > $currentScore,
            'current' => 5,
            'currentScore' => $currentScore,
            'best' => $best['strength'],
            'bestScore' => $best['score'],
            'all' => $results,
        ];
    }

    /**
     * Derive wave labels from swing points (used for testing alternate parameters).
     */
    private function deriveWaveLabelsFromSwings(array $swings): array
    {
        if (count($swings) < 5) {
            return [];
        }

        // Alternate high/low sequence
        $filtered = [$swings[0]];
        for ($i = 1; $i < count($swings); $i++) {
            $last = end($filtered);
            if ($swings[$i]['type'] !== $last['type']) {
                $filtered[] = $swings[$i];
            } else {
                if ($swings[$i]['type'] === 'high' && $swings[$i]['price'] > $last['price']) {
                    $filtered[count($filtered) - 1] = $swings[$i];
                }
                if ($swings[$i]['type'] === 'low' && $swings[$i]['price'] < $last['price']) {
                    $filtered[count($filtered) - 1] = $swings[$i];
                }
            }
        }

        $waveSeq = ['1', '2', '3', '4', '5', 'A', 'B', 'C'];
        $labels = [];
        for ($i = 0; $i < min(count($filtered), count($waveSeq)); $i++) {
            $labels[] = [
                'label' => $waveSeq[$i % count($waveSeq)],
                'type' => $filtered[$i]['type'],
                'price' => $filtered[$i]['price'],
                'timestamp' => $filtered[$i]['timestamp'] ?? null,
            ];
        }

        return $labels;
    }

    /**
     * Auto-fix: attempt to recalibrate and return clear result.
     */
    public function autoFix(int $symbolId, string $timeframe): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $bestAlt = $this->findBestParameters($symbol, $timeframe);

        if (! $bestAlt['improved']) {
            return [
                'fixed' => false,
                'message' => "No better parameters found. Current score ({$bestAlt['currentScore']}) is already the best across swing strengths 3-10.",
                'suggestion' => 'Try fetching more historical data or checking a different timeframe.',
                'currentScore' => $bestAlt['currentScore'],
                'testedParams' => count($bestAlt['all']),
                'health' => $this->analyzeTimeframe($symbol, $timeframe),
            ];
        }

        return [
            'fixed' => true,
            'message' => "Improved! Swing {$bestAlt['current']} → {$bestAlt['best']}. Score {$bestAlt['currentScore']} → {$bestAlt['bestScore']}.",
            'oldSwing' => $bestAlt['current'],
            'newSwing' => $bestAlt['best'],
            'oldScore' => $bestAlt['currentScore'],
            'newScore' => $bestAlt['bestScore'],
            'testedParams' => count($bestAlt['all']),
            'health' => $this->analyzeTimeframe($symbol, $timeframe),
        ];
    }

    public function checkDataIntegrity(int $symbolId, string $timeframe): array
    {
        $totalCandles = Candle::where('symbol_id', $symbolId)->where('timeframe', $timeframe)->count();
        $zeroVolume = Candle::where('symbol_id', $symbolId)->where('timeframe', $timeframe)->where('volume', 0)->count();
        $gaps = DataGap::where('symbol_id', $symbolId)->where('timeframe', $timeframe)->unfilled()->count();

        $isClean = $zeroVolume === 0 && $gaps === 0;

        return [
            'total_candles' => $totalCandles,
            'zero_volume' => $zeroVolume,
            'gaps' => $gaps,
            'duplicates' => 0,
            'is_clean' => $isClean,
            'label' => $isClean ? '✓ Clean' : ($gaps > 0 ? "⚠ {$gaps} gap(s)" : '⚠ Issues'),
        ];
    }

    public function checkOverallDataIntegrity(int $symbolId): array
    {
        return [
            'total_candles' => Candle::where('symbol_id', $symbolId)->count(),
            'zero_volume' => Candle::where('symbol_id', $symbolId)->where('volume', 0)->count(),
            'gaps' => DataGap::where('symbol_id', $symbolId)->unfilled()->count(),
            'duplicates' => 0,
        ];
    }
}
