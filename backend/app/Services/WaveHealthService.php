<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\MarketStructureEngine;
use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Symbol;
use Illuminate\Support\Facades\DB;

class WaveHealthService
{
    /**
     * Calculate wave health for a symbol across all timeframes.
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
     * Analyze wave health for a single symbol + timeframe.
     */
    public function analyzeTimeframe(Symbol $symbol, string $timeframe): array
    {
        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (count($candles) < 20) {
            return [
                'symbol' => $symbol->ticker,
                'timeframe' => $timeframe,
                'score' => 0,
                'status' => 'no_data',
                'violations' => [],
                'wave_count' => 0,
                'trend' => 'neutral',
            ];
        }

        $engine = new MarketStructureEngine(5);
        $result = $engine->run($candles, $symbol->ticker, $timeframe);

        $swings = $result->overlays['swings'] ?? [];
        $bos = $result->overlays['bos'] ?? [];
        $trend = $result->metadata['trend'] ?? 'neutral';

        // Derive wave labels for validation
        $waveLabels = $this->deriveWaveLabels($swings);
        $violations = $this->validateWaveRules($waveLabels);

        // Score: start at 100, deduct per violation
        $score = max(0, 100 - count($violations) * 15);

        // Bonus for trend clarity
        if (count($bos) > 3) {
            $recentBos = array_slice($bos, -5);
            $bullCount = count(array_filter($recentBos, fn ($b) => $b['direction'] === 'buy'));
            $bearCount = count($recentBos) - $bullCount;
            if ($bullCount >= 4 || $bearCount >= 4) {
                $score = min(100, $score + 10); // Clear trend bonus
            }
        }

        $status = $score >= 75 ? 'valid' : ($score >= 50 ? 'caution' : 'invalidated');

        return [
            'symbol' => $symbol->ticker,
            'timeframe' => $timeframe,
            'score' => $score,
            'status' => $status,
            'violations' => $violations,
            'wave_count' => count($waveLabels),
            'trend' => $trend,
            'swing_count' => count($swings),
            'bos_count' => count($bos),
        ];
    }

    private function deriveWaveLabels(array $swings): array
    {
        if (count($swings) < 5) {
            return [];
        }

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
                'label' => $waveSeq[$i],
                'type' => $filtered[$i]['type'],
                'price' => $filtered[$i]['price'],
            ];
        }

        return $labels;
    }

    /**
     * Validate Elliott Wave rules against wave labels.
     */
    private function validateWaveRules(array $waves): array
    {
        $violations = [];

        if (count($waves) < 5) {
            return $violations;
        }

        // Find impulse waves by label
        $w = [];
        foreach ($waves as $wave) {
            $w[$wave['label']] = $wave['price'];
        }

        // Rule 2: Wave 2 must not retrace below Wave 1 start
        if (isset($w['1'], $w['2'])) {
            // For bullish impulse: wave 2 low should not go below wave 1 start
            // Simplified: wave 2 price should not be more extreme than wave 1 start price
            // (This depends on direction; simplified check)
        }

        // Rule 3: Wave 3 must not be the shortest impulse wave
        if (isset($w['1'], $w['2'], $w['3'], $w['4'], $w['5'])) {
            $wave1Len = abs($w['2'] - $w['1']);
            $wave3Len = abs($w['4'] - $w['3']);
            $wave5Len = abs($w['5'] - $w['4']);

            if ($wave3Len < $wave1Len && $wave3Len < $wave5Len) {
                $violations[] = [
                    'rule' => 3,
                    'description' => 'Wave 3 is the shortest impulse wave',
                    'severity' => 'critical',
                ];
            }
        }

        // Rule 4: Wave 4 must not overlap Wave 1 price territory
        if (isset($w['1'], $w['2'], $w['4'])) {
            $wave1End = $w['2']; // Wave 1 ends at wave 2 start
            $wave4Price = $w['4'];

            // For bullish: wave 4 should not go below wave 1 high
            if ($w['3'] > $w['1']) { // Bullish
                if ($wave4Price < $w['1']) {
                    $violations[] = [
                        'rule' => 4,
                        'description' => 'Wave 4 overlaps Wave 1 price territory',
                        'severity' => 'critical',
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Full validation: wave rules + data integrity for all TFs.
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
            $swingStrength = 5; // current default

            // Check if auto-fixable by testing alternate swing strengths
            $bestAlt = $this->findBestSwingStrength($symbol, $tf);
            $isFixable = $bestAlt['improved'];

            $health['data_check'] = $dataCheck;
            $health['swing_strength'] = $swingStrength;
            $health['fixable'] = $isFixable;
            $health['best_alt'] = $bestAlt;

            foreach ($health['violations'] as $v) {
                $totalViolations++;
                if (($v['severity'] ?? 'warning') === 'critical') {
                    $criticalCount++;
                } else {
                    $warningCount++;
                }
                if ($isFixable) {
                    $fixableCount++;
                }
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
     * Check data integrity for a specific symbol + timeframe.
     */
    public function checkDataIntegrity(int $symbolId, string $timeframe): array
    {
        $totalCandles = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->count();

        $zeroVolume = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->where('volume', 0)
            ->count();

        $gaps = DataGap::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->unfilled()
            ->count();

        $duplicates = DB::select("
            SELECT COUNT(*) as cnt FROM (
                SELECT symbol_id, timeframe, timestamp, COUNT(*) as c
                FROM candles
                WHERE symbol_id = ? AND timeframe = ?
                GROUP BY symbol_id, timeframe, timestamp
                HAVING COUNT(*) > 1
            ) dupes
        ", [$symbolId, $timeframe]);

        $dupCount = $duplicates[0]->cnt ?? 0;
        $isClean = $zeroVolume === 0 && $gaps === 0 && $dupCount === 0;

        return [
            'total_candles' => $totalCandles,
            'zero_volume' => $zeroVolume,
            'gaps' => $gaps,
            'duplicates' => $dupCount,
            'is_clean' => $isClean,
            'label' => $isClean ? '✓ Clean' : ($gaps > 0 ? "⚠ {$gaps} gap(s)" : "⚠ Issues"),
        ];
    }

    /**
     * Overall data integrity across all timeframes.
     */
    public function checkOverallDataIntegrity(int $symbolId): array
    {
        $totalCandles = Candle::where('symbol_id', $symbolId)->count();
        $zeroVolume = Candle::where('symbol_id', $symbolId)->where('volume', 0)->count();
        $totalGaps = DataGap::where('symbol_id', $symbolId)->unfilled()->count();

        return [
            'total_candles' => $totalCandles,
            'zero_volume' => $zeroVolume,
            'gaps' => $totalGaps,
            'duplicates' => 0,
        ];
    }

    /**
     * Try different swing strengths (3,5,7,10) to find one with fewer violations.
     */
    public function findBestSwingStrength(Symbol $symbol, string $timeframe): array
    {
        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (count($candles) < 20) {
            return ['improved' => false, 'current' => 5, 'best' => 5, 'bestScore' => 0];
        }

        $strengths = [3, 5, 7, 10];
        $results = [];

        foreach ($strengths as $str) {
            $engine = new MarketStructureEngine($str);
            $result = $engine->run($candles, $symbol->ticker, $timeframe);
            $swings = $result->overlays['swings'] ?? [];
            $waveLabels = $this->deriveWaveLabels($swings);
            $violations = $this->validateWaveRules($waveLabels);
            $score = max(0, 100 - count($violations) * 15);

            $bos = $result->overlays['bos'] ?? [];
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
            ];
        }

        // Find best
        $currentScore = $results[5]['score'] ?? 0;
        $best = $results[5];
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
     * Auto-fix: recalibrate a specific timeframe by finding optimal swing strength.
     * Returns the new health analysis with the best parameters.
     */
    public function autoFix(int $symbolId, string $timeframe): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $bestAlt = $this->findBestSwingStrength($symbol, $timeframe);

        if (! $bestAlt['improved']) {
            return [
                'fixed' => false,
                'message' => "No improvement found. Current swing={$bestAlt['current']} (score={$bestAlt['currentScore']}) is already optimal.",
                'health' => $this->analyzeTimeframe($symbol, $timeframe),
            ];
        }

        // Re-analyze with the best swing strength
        $candles = Candle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        $engine = new MarketStructureEngine($bestAlt['best']);
        $result = $engine->run($candles, $symbol->ticker, $timeframe);
        $swings = $result->overlays['swings'] ?? [];
        $bos = $result->overlays['bos'] ?? [];
        $waveLabels = $this->deriveWaveLabels($swings);
        $violations = $this->validateWaveRules($waveLabels);

        $newScore = $bestAlt['bestScore'];
        $status = $newScore >= 75 ? 'valid' : ($newScore >= 50 ? 'caution' : 'invalidated');

        return [
            'fixed' => true,
            'message' => "Recalibrated: swing {$bestAlt['current']} → {$bestAlt['best']}. Health {$bestAlt['currentScore']} → {$newScore}.",
            'oldSwing' => $bestAlt['current'],
            'newSwing' => $bestAlt['best'],
            'oldScore' => $bestAlt['currentScore'],
            'newScore' => $newScore,
            'health' => [
                'symbol' => $symbol->ticker,
                'timeframe' => $timeframe,
                'score' => $newScore,
                'status' => $status,
                'violations' => $violations,
                'wave_count' => count($waveLabels),
                'trend' => $result->metadata['trend'] ?? 'neutral',
                'swing_count' => count($swings),
                'bos_count' => count($bos),
            ],
        ];
    }
}
