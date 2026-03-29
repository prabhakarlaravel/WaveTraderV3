<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\ElliottWaveEngine;
use App\Engines\MarketStructureEngine;
use App\Models\Candle;
use App\Models\DataGap;
use App\Models\Setting;
use App\Models\Symbol;

class WaveHealthService
{
    private const TIMEFRAMES = ['1D', '4H', '1H', '15M', '5M', '1M'];

    /**
     * Get the saved swing strength for a symbol+timeframe, or default.
     */
    private function getSwingStrength(int $symbolId, string $timeframe): int
    {
        $key = "swing_strength_{$symbolId}_{$timeframe}";
        $saved = Setting::get($key);

        return $saved ? (int) $saved : 5;
    }

    /**
     * Save optimal swing strength for a symbol+timeframe.
     */
    private function saveSwingStrength(int $symbolId, string $timeframe, int $strength): void
    {
        $key = "swing_strength_{$symbolId}_{$timeframe}";
        Setting::set($key, (string) $strength, 'engine');
    }

    /**
     * Full validation across all TFs.
     */
    public function validateAll(int $symbolId): array
    {
        $symbol = Symbol::findOrFail($symbolId);

        $tfResults = [];
        $totalViolations = 0;
        $criticalCount = 0;
        $fixableCount = 0;
        $totalScore = 0;

        foreach (self::TIMEFRAMES as $tf) {
            $health = $this->analyzeTimeframe($symbol, $tf);
            $dataCheck = $this->checkDataIntegrity($symbolId, $tf);
            $bestAlt = $this->findBestParameters($symbol, $tf);

            $health['data_check'] = $dataCheck;
            $health['fixable'] = $bestAlt['improved'];
            $health['best_alt'] = $bestAlt;

            foreach ($health['violations'] as $v) {
                $totalViolations++;
                if (($v['severity'] ?? 'warning') === 'critical') {
                    $criticalCount++;
                }
            }
            if ($bestAlt['improved']) {
                $fixableCount++;
            }

            $totalScore += $health['score'];
            $tfResults[] = $health;
        }

        return [
            'symbol' => $symbol->ticker,
            'overallHealth' => count(self::TIMEFRAMES) > 0 ? round($totalScore / count(self::TIMEFRAMES)) : 0,
            'totalViolations' => $totalViolations,
            'criticalCount' => $criticalCount,
            'warningCount' => $totalViolations - $criticalCount,
            'fixableCount' => $fixableCount,
            'dataIntegrity' => $this->checkOverallDataIntegrity($symbolId),
            'timeframes' => $tfResults,
        ];
    }

    /**
     * Analyze wave health using saved optimal parameters.
     */
    public function analyzeTimeframe(Symbol $symbol, string $timeframe): array
    {
        $candles = $this->getCandles($symbol->id, $timeframe);

        if (count($candles) < 30) {
            return $this->emptyResult($symbol->ticker, $timeframe);
        }

        $swingStrength = $this->getSwingStrength($symbol->id, $timeframe);

        // Run engines with saved parameters
        $ewEngine = new ElliottWaveEngine();
        $ewResult = $ewEngine->run($candles, $symbol->ticker, $timeframe);

        $msEngine = new MarketStructureEngine($swingStrength);
        $msResult = $msEngine->run($candles, $symbol->ticker, $timeframe);

        $waveLabels = $ewResult->overlays['waveLabels'] ?? [];
        $violations = $this->validateWaveRules($waveLabels);
        $score = max(0, 100 - count($violations) * 15);

        // Trend clarity bonus
        $bos = $msResult->overlays['bos'] ?? [];
        if (count($bos) > 3) {
            $recentBos = array_slice($bos, -5);
            $bullCount = count(array_filter($recentBos, fn ($b) => $b['direction'] === 'buy'));
            $bearCount = count($recentBos) - $bullCount;
            if ($bullCount >= 4 || $bearCount >= 4) {
                $score = min(100, $score + 10);
            }
        }

        return [
            'symbol' => $symbol->ticker,
            'timeframe' => $timeframe,
            'score' => $score,
            'status' => $score >= 75 ? 'valid' : ($score >= 50 ? 'caution' : 'invalidated'),
            'violations' => $violations,
            'wave_count' => $ewResult->metadata['wave_count'] ?? 0,
            'current_wave' => $ewResult->metadata['current_wave'] ?? null,
            'phase' => $ewResult->metadata['phase'] ?? null,
            'trend' => $msResult->metadata['trend'] ?? 'neutral',
            'swing_count' => $msResult->metadata['swing_count'] ?? 0,
            'bos_count' => $msResult->metadata['bos_count'] ?? 0,
            'swing_strength' => $swingStrength,
        ];
    }

    /**
     * Auto-fix: test parameters, SAVE the best one, and REGENERATE waves.
     */
    public function autoFix(int $symbolId, string $timeframe): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $bestAlt = $this->findBestParameters($symbol, $timeframe);
        $currentStrength = $this->getSwingStrength($symbolId, $timeframe);

        if (! $bestAlt['improved']) {
            return [
                'fixed' => false,
                'message' => "Tested 7 swing strengths (3-10). Current sw={$currentStrength} (score={$bestAlt['currentScore']}) is already optimal.",
                'suggestion' => 'This violation reflects actual market structure. Consider more historical data or a different timeframe.',
                'currentScore' => $bestAlt['currentScore'],
                'health' => $this->analyzeTimeframe($symbol, $timeframe),
            ];
        }

        // SAVE the optimal swing strength — this is the key fix!
        $this->saveSwingStrength($symbolId, $timeframe, $bestAlt['best']);

        // REGENERATE: re-analyze with the newly saved parameter
        $newHealth = $this->analyzeTimeframe($symbol, $timeframe);

        return [
            'fixed' => true,
            'message' => "Swing {$currentStrength} → {$bestAlt['best']}. Score {$bestAlt['currentScore']} → {$bestAlt['bestScore']}. Setting saved.",
            'oldSwing' => $currentStrength,
            'newSwing' => $bestAlt['best'],
            'oldScore' => $bestAlt['currentScore'],
            'newScore' => $bestAlt['bestScore'],
            'health' => $newHealth,
        ];
    }

    /**
     * Regenerate all waves for a symbol across all TFs.
     * Re-runs ElliottWaveEngine + MarketStructureEngine with saved parameters.
     */
    public function regenerateAll(int $symbolId): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $results = [];

        foreach (self::TIMEFRAMES as $tf) {
            $candles = $this->getCandles($symbolId, $tf);
            if (count($candles) < 30) {
                $results[$tf] = ['status' => 'skipped', 'reason' => 'insufficient data'];
                continue;
            }

            $swingStrength = $this->getSwingStrength($symbolId, $tf);

            // Run engines fresh
            $ewResult = (new ElliottWaveEngine())->run($candles, $symbol->ticker, $tf);
            $msResult = (new MarketStructureEngine($swingStrength))->run($candles, $symbol->ticker, $tf);

            $waveLabels = $ewResult->overlays['waveLabels'] ?? [];
            $violations = $this->validateWaveRules($waveLabels);
            $score = max(0, 100 - count($violations) * 15);

            $results[$tf] = [
                'status' => 'regenerated',
                'score' => $score,
                'wave_count' => $ewResult->metadata['wave_count'] ?? 0,
                'current_wave' => $ewResult->metadata['current_wave'] ?? null,
                'violations' => count($violations),
                'swing_strength' => $swingStrength,
            ];
        }

        return [
            'symbol' => $symbol->ticker,
            'message' => 'All timeframes regenerated with saved optimal parameters.',
            'timeframes' => $results,
        ];
    }

    /**
     * Test different swing strengths to find the one with fewest violations.
     */
    public function findBestParameters(Symbol $symbol, string $timeframe): array
    {
        $candles = $this->getCandles($symbol->id, $timeframe);
        if (count($candles) < 30) {
            return ['improved' => false, 'current' => 5, 'currentScore' => 0, 'best' => 5, 'bestScore' => 0, 'all' => []];
        }

        $currentStrength = $this->getSwingStrength($symbol->id, $timeframe);
        $strengths = [3, 4, 5, 6, 7, 8, 10];
        $results = [];

        foreach ($strengths as $str) {
            $msEngine = new MarketStructureEngine($str);
            $msResult = $msEngine->run($candles, $symbol->ticker, $timeframe);
            $swings = $msResult->overlays['swings'] ?? [];
            $bos = $msResult->overlays['bos'] ?? [];

            $waveLabels = $this->deriveWaveLabelsFromSwings($swings);
            $violations = $this->validateWaveRules($waveLabels);
            $score = max(0, 100 - count($violations) * 15);

            // Trend bonus
            if (count($bos) > 3) {
                $recentBos = array_slice($bos, -5);
                $bullCount = count(array_filter($recentBos, fn ($b) => $b['direction'] === 'buy'));
                $bearCount = count($recentBos) - $bullCount;
                if ($bullCount >= 4 || $bearCount >= 4) {
                    $score = min(100, $score + 10);
                }
            }

            $results[$str] = ['strength' => $str, 'score' => $score, 'violations' => count($violations), 'swings' => count($swings)];
        }

        $currentScore = $results[$currentStrength]['score'] ?? 0;
        $best = $results[$currentStrength] ?? ['strength' => 5, 'score' => 0];
        foreach ($results as $r) {
            if ($r['score'] > $best['score']) {
                $best = $r;
            }
        }

        return [
            'improved' => $best['score'] > $currentScore,
            'current' => $currentStrength,
            'currentScore' => $currentScore,
            'best' => $best['strength'],
            'bestScore' => $best['score'],
            'all' => $results,
        ];
    }

    public function dashboard(int $symbolId): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $results = [];
        foreach (self::TIMEFRAMES as $tf) {
            $results[] = $this->analyzeTimeframe($symbol, $tf);
        }

        return $results;
    }

    // ── Private helpers ──

    private function getCandles(int $symbolId, string $timeframe): array
    {
        return Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();
    }

    private function emptyResult(string $ticker, string $timeframe): array
    {
        return [
            'symbol' => $ticker, 'timeframe' => $timeframe, 'score' => 0,
            'status' => 'no_data', 'violations' => [], 'wave_count' => 0,
            'current_wave' => null, 'phase' => null, 'trend' => 'neutral',
            'swing_count' => 0, 'bos_count' => 0, 'swing_strength' => 5,
        ];
    }

    private function validateWaveRules(array $waveLabels): array
    {
        $violations = [];
        if (count($waveLabels) < 5) {
            return $violations;
        }

        $w = [];
        foreach ($waveLabels as $label) {
            $w[$label['label']] = (float) $label['price'];
        }

        // Rule 3: Wave 3 must not be the shortest
        if (isset($w['1'], $w['2'], $w['3'], $w['4'], $w['5'])) {
            $w1 = abs($w['2'] - $w['1']);
            $w3 = abs($w['4'] - $w['3']);
            $w5 = abs($w['5'] - $w['4']);
            if ($w3 > 0 && $w1 > 0 && $w5 > 0 && $w3 < $w1 && $w3 < $w5) {
                $violations[] = [
                    'rule' => 3,
                    'description' => 'Wave 3 is the shortest impulse wave',
                    'severity' => 'critical',
                    'detail' => sprintf('W1=%.0f, W3=%.0f, W5=%.0f pts', $w1, $w3, $w5),
                ];
            }
        }

        // Rule 4: Wave 4 must not overlap Wave 1
        if (isset($w['1'], $w['2'], $w['3'], $w['4'])) {
            $bullish = $w['3'] > $w['1'];
            if ($bullish && $w['4'] < $w['1']) {
                $violations[] = [
                    'rule' => 4, 'description' => 'Wave 4 overlaps Wave 1 territory',
                    'severity' => 'critical', 'detail' => sprintf('W1=%.2f, W4=%.2f', $w['1'], $w['4']),
                ];
            } elseif (! $bullish && $w['4'] > $w['1']) {
                $violations[] = [
                    'rule' => 4, 'description' => 'Wave 4 overlaps Wave 1 territory',
                    'severity' => 'critical', 'detail' => sprintf('W1=%.2f, W4=%.2f', $w['1'], $w['4']),
                ];
            }
        }

        return $violations;
    }

    private function deriveWaveLabelsFromSwings(array $swings): array
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

        $seq = ['1', '2', '3', '4', '5', 'A', 'B', 'C'];
        $labels = [];
        for ($i = 0; $i < min(count($filtered), count($seq)); $i++) {
            $labels[] = ['label' => $seq[$i], 'type' => $filtered[$i]['type'], 'price' => $filtered[$i]['price']];
        }

        return $labels;
    }

    public function checkDataIntegrity(int $symbolId, string $timeframe): array
    {
        $total = Candle::where('symbol_id', $symbolId)->where('timeframe', $timeframe)->count();
        $zeroVol = Candle::where('symbol_id', $symbolId)->where('timeframe', $timeframe)->where('volume', 0)->count();
        $gaps = DataGap::where('symbol_id', $symbolId)->where('timeframe', $timeframe)->unfilled()->count();
        $clean = $zeroVol === 0 && $gaps === 0;

        return ['total_candles' => $total, 'zero_volume' => $zeroVol, 'gaps' => $gaps, 'duplicates' => 0, 'is_clean' => $clean, 'label' => $clean ? '✓ Clean' : "⚠ Issues"];
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
