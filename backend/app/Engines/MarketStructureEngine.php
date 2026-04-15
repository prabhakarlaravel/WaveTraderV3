<?php

declare(strict_types=1);

namespace App\Engines;

class MarketStructureEngine implements EngineInterface
{
    private const LOOKBACK_MAP = [
        '1M' => 3, '5M' => 4, '15M' => 5, '1H' => 8, '4H' => 12, '1D' => 20,
    ];

    /**
     * Recent-window size for BOS counting, per timeframe.
     *
     * These values were widened from the original (240/200/150/120/80/60)
     * because the narrower windows captured only the tail of a pullback
     * and missed the preceding impulse leg entirely — e.g. 200 bars on 5M
     * is ~2.5 NSE sessions, far too short to catch a multi-day trend.
     * Current values target roughly 5-8 full sessions per timeframe.
     */
    private const RECENT_BARS_MAP = [
        '1M' => 1500, '5M' => 500, '15M' => 400, '1H' => 300, '4H' => 200, '1D' => 120,
    ];

    private int $lookback;

    public function __construct(int $lookback = 5)
    {
        $this->lookback = $lookback;
    }

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        $lookback = self::LOOKBACK_MAP[$timeframe] ?? $this->lookback;

        if (count($candles) < $lookback * 2 + 1) {
            return new EngineResult(engine: 'market_structure', symbol: $symbol, timeframe: $timeframe);
        }

        $swings = $this->detectSwings($candles, $lookback);
        $structures = $this->detectBosChoch($swings, $candles);

        // Count BOS events only within the recent window. A cumulative
        // 3-month BOS tally overrode the live trend signal; now we count
        // net recent BOS (bullish - bearish) so trend reflects "now".
        $recentBars = self::RECENT_BARS_MAP[$timeframe] ?? 200;
        $candleCount = count($candles);
        $cutoffTs = $candleCount > $recentBars
            ? ($candles[$candleCount - $recentBars]['timestamp'] ?? null)
            : null;

        $recentBos = $structures['bos'];
        if ($cutoffTs !== null) {
            $recentBos = array_values(array_filter(
                $structures['bos'],
                fn ($b) => isset($b['timestamp']) && $b['timestamp'] >= $cutoffTs,
            ));
        }

        $recentBullBos = 0;
        $recentBearBos = 0;
        foreach ($recentBos as $b) {
            if (($b['direction'] ?? '') === 'buy') {
                $recentBullBos++;
            } elseif (($b['direction'] ?? '') === 'sell') {
                $recentBearBos++;
            }
        }
        $netRecentBos = abs($recentBullBos - $recentBearBos);

        // ── Classical structural trend detection ──
        // A single BOS count is unreliable: a window can have 2 bull + 4
        // bear BOS yet still be +2% net bullish (a run of big bullish
        // candles followed by a small pullback). We combine three signals:
        //
        //   1. Swing structure in the window: are the last 3 swing highs
        //      ascending AND last 3 swing lows ascending? → bullish
        //      (mirror for bearish).
        //   2. Net price change over the window (close-to-close).
        //   3. BOS count as a weaker tie-breaker only.
        //
        // Swing structure dominates; if it disagrees with price direction
        // we fall back to price direction; BOS count is used last.
        $windowSwings = $swings;
        if ($cutoffTs !== null) {
            $windowSwings = array_values(array_filter(
                $swings,
                fn ($s) => isset($s['timestamp']) && $s['timestamp'] >= $cutoffTs,
            ));
        }
        $structuralTrend = $this->deriveStructuralTrend($windowSwings);

        // Net price direction across the recent window
        $priceTrend = 'neutral';
        $pricePct = 0.0;
        if ($candleCount > 0) {
            $startIdx = max(0, $candleCount - $recentBars);
            $startClose = (float) ($candles[$startIdx]['close'] ?? 0);
            $endClose   = (float) ($candles[$candleCount - 1]['close'] ?? 0);
            if ($startClose > 0) {
                $pricePct = ($endClose - $startClose) / $startClose * 100;
                // Thresholds: >0.5% = directional, <=0.5% = neutral
                if ($pricePct > 0.5) {
                    $priceTrend = 'bullish';
                } elseif ($pricePct < -0.5) {
                    $priceTrend = 'bearish';
                }
            }
        }

        // BOS-count trend (weakest signal)
        $bosTrend = 'neutral';
        if ($recentBullBos > $recentBearBos) {
            $bosTrend = 'bullish';
        } elseif ($recentBearBos > $recentBullBos) {
            $bosTrend = 'bearish';
        }

        // Combine: structure > price > BOS
        // Prefer structural trend if it is directional AND not contradicted
        // by a strongly opposite price move.
        $recentTrend = 'neutral';
        if ($structuralTrend !== 'neutral') {
            // Structural says one thing — honour it unless price strongly
            // disagrees (e.g. structure=bullish but price is −3% or more).
            if ($priceTrend !== 'neutral' && $priceTrend !== $structuralTrend && abs($pricePct) >= 3.0) {
                $recentTrend = $priceTrend;
            } else {
                $recentTrend = $structuralTrend;
            }
        } elseif ($priceTrend !== 'neutral') {
            $recentTrend = $priceTrend;
        } elseif ($bosTrend !== 'neutral') {
            $recentTrend = $bosTrend;
        }

        // Window signals to the same recent cutoff as BOS metadata.
        // Without this, 3 months of cumulative buy/sell BOS signals vote
        // in ConfluenceEngine, burying the live trend under history.
        $recentSignals = $structures['signals'];
        if ($cutoffTs !== null) {
            $recentSignals = array_values(array_filter(
                $structures['signals'],
                fn ($s) => isset($s['candle_timestamp']) && $s['candle_timestamp'] >= $cutoffTs,
            ));
        }

        return new EngineResult(
            engine: 'market_structure',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $recentSignals,
            overlays: [
                'swings' => $swings,
                'bos' => $structures['bos'],
            ],
            metadata: [
                'swing_count' => count($swings),
                'bos_count' => count($recentBos),           // recent window only
                'bos_count_total' => count($structures['bos']), // legacy, kept for UI
                'recent_bull_bos' => $recentBullBos,
                'recent_bear_bos' => $recentBearBos,
                'net_recent_bos' => $netRecentBos,
                'trend' => $recentTrend,
                'trend_raw' => $structures['trend'],
                'trend_structural' => $structuralTrend,
                'trend_price' => $priceTrend,
                'trend_price_pct' => round($pricePct, 2),
                'trend_bos' => $bosTrend,
                'recent_bars_window' => $recentBars,
            ],
        );
    }

    /**
     * Classical structural trend detector.
     *
     * Rules:
     *   • Bullish: last 3 swing highs strictly ascending AND last 3 swing
     *     lows strictly ascending (HH + HL).
     *   • Bearish: last 3 swing highs strictly descending AND last 3 swing
     *     lows strictly descending (LH + LL).
     *   • Otherwise neutral.
     *
     * Only looks at swings inside the recent window; requires at least 3
     * highs AND 3 lows to declare a direction.
     */
    private function deriveStructuralTrend(array $windowSwings): string
    {
        $highs = [];
        $lows = [];
        foreach ($windowSwings as $s) {
            if (($s['type'] ?? '') === 'high') {
                $highs[] = (float) $s['price'];
            } elseif (($s['type'] ?? '') === 'low') {
                $lows[] = (float) $s['price'];
            }
        }

        if (count($highs) < 3 || count($lows) < 3) {
            return 'neutral';
        }

        // Take the most recent 3 of each
        $recentHighs = array_slice($highs, -3);
        $recentLows  = array_slice($lows, -3);

        $isAscendingHighs = $recentHighs[2] > $recentHighs[1] && $recentHighs[1] > $recentHighs[0];
        $isAscendingLows  = $recentLows[2]  > $recentLows[1]  && $recentLows[1]  > $recentLows[0];
        $isDescendingHighs = $recentHighs[2] < $recentHighs[1] && $recentHighs[1] < $recentHighs[0];
        $isDescendingLows  = $recentLows[2]  < $recentLows[1]  && $recentLows[1]  < $recentLows[0];

        // Strict HH+HL → bullish
        if ($isAscendingHighs && $isAscendingLows) {
            return 'bullish';
        }
        // Strict LH+LL → bearish
        if ($isDescendingHighs && $isDescendingLows) {
            return 'bearish';
        }

        // Looser variant: if highs ascending and lows NOT strictly descending,
        // still bullish (classic uptrend with noisy lows).
        if ($isAscendingHighs && ! $isDescendingLows) {
            return 'bullish';
        }
        if ($isDescendingLows && ! $isAscendingHighs) {
            return 'bearish';
        }

        return 'neutral';
    }

    /**
     * Detect swing highs and lows using N-bar lookback.
     */
    private function detectSwings(array $candles, ?int $lookback = null): array
    {
        $swings = [];
        $n = $lookback ?? $this->lookback;

        for ($i = $n; $i < count($candles) - $n; $i++) {
            $isSwingHigh = true;
            $isSwingLow = true;

            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];

            for ($j = 1; $j <= $n; $j++) {
                if ((float) $candles[$i - $j]['high'] >= $high || (float) $candles[$i + $j]['high'] >= $high) {
                    $isSwingHigh = false;
                }
                if ((float) $candles[$i - $j]['low'] <= $low || (float) $candles[$i + $j]['low'] <= $low) {
                    $isSwingLow = false;
                }
            }

            if ($isSwingHigh) {
                $swings[] = [
                    'type' => 'high',
                    'price' => $high,
                    'timestamp' => $candles[$i]['timestamp'],
                    'index' => $i,
                ];
            }

            if ($isSwingLow) {
                $swings[] = [
                    'type' => 'low',
                    'price' => $low,
                    'timestamp' => $candles[$i]['timestamp'],
                    'index' => $i,
                ];
            }
        }

        // Sort by index
        usort($swings, fn ($a, $b) => $a['index'] <=> $b['index']);

        return $swings;
    }

    /**
     * Detect Break of Structure (BOS) and Change of Character (CHOCH).
     *
     * A break must be sustained for at least 2 candles after the swing index
     * to be confirmed. Unconfirmed breaks are discarded entirely.
     */
    private function detectBosChoch(array $swings, array $candles): array
    {
        $signals = [];
        $bos = [];
        $lastSwingHigh = null;
        $lastSwingLow = null;
        $trend = 'neutral'; // 'bullish', 'bearish', 'neutral'
        $candleCount = count($candles);

        // Pre-compute a simple ATR (14-period) for confidence adjustment.
        $atr = $this->computeAtr($candles, 14);

        foreach ($swings as $swing) {
            if ($swing['type'] === 'high') {
                if ($lastSwingHigh !== null && $swing['price'] > $lastSwingHigh['price']) {
                    // Bullish BOS — higher high
                    $type = $trend === 'bearish' ? 'choch' : 'bos';
                    $direction = 'buy';
                    $level = $lastSwingHigh['price'];

                    // 2-candle confirmation: candles at index+1 and index+2 must close above the broken level
                    $idx = $swing['index'];
                    if ($idx + 2 >= $candleCount) {
                        $lastSwingHigh = $swing;
                        continue; // not enough candles to confirm
                    }
                    $close1 = (float) $candles[$idx + 1]['close'];
                    $close2 = (float) $candles[$idx + 2]['close'];
                    if ($close1 <= $level || $close2 <= $level) {
                        $lastSwingHigh = $swing;
                        continue; // confirmation failed — skip
                    }

                    // Confidence: reduce BOS to 50 if the break is within 0.3×ATR
                    $breakMargin = $swing['price'] - $level;
                    $baseConfidence = $type === 'choch' ? 80 : 60;
                    if ($type === 'bos' && $atr > 0.0 && $breakMargin < 0.3 * $atr) {
                        $baseConfidence = 50;
                    }

                    $bos[] = [
                        'type' => $type,
                        'direction' => $direction,
                        'price' => $level,
                        'break_price' => $swing['price'],
                        'timestamp' => $swing['timestamp'],
                    ];

                    $signals[] = [
                        'timeframe' => '', // filled by caller
                        'engine' => 'market_structure',
                        'direction' => $direction,
                        'entry' => $level,
                        'sl' => $lastSwingLow ? $lastSwingLow['price'] : null,
                        'tp' => null,
                        'confluence_score' => $baseConfidence,
                        'candle_timestamp' => $swing['timestamp'],
                    ];

                    $trend = 'bullish';
                }
                $lastSwingHigh = $swing;
            }

            if ($swing['type'] === 'low') {
                if ($lastSwingLow !== null && $swing['price'] < $lastSwingLow['price']) {
                    // Bearish BOS — lower low
                    $type = $trend === 'bullish' ? 'choch' : 'bos';
                    $direction = 'sell';
                    $level = $lastSwingLow['price'];

                    // 2-candle confirmation: candles at index+1 and index+2 must close below the broken level
                    $idx = $swing['index'];
                    if ($idx + 2 >= $candleCount) {
                        $lastSwingLow = $swing;
                        continue; // not enough candles to confirm
                    }
                    $close1 = (float) $candles[$idx + 1]['close'];
                    $close2 = (float) $candles[$idx + 2]['close'];
                    if ($close1 >= $level || $close2 >= $level) {
                        $lastSwingLow = $swing;
                        continue; // confirmation failed — skip
                    }

                    // Confidence: reduce BOS to 50 if the break is within 0.3×ATR
                    $breakMargin = $level - $swing['price'];
                    $baseConfidence = $type === 'choch' ? 80 : 60;
                    if ($type === 'bos' && $atr > 0.0 && $breakMargin < 0.3 * $atr) {
                        $baseConfidence = 50;
                    }

                    $bos[] = [
                        'type' => $type,
                        'direction' => $direction,
                        'price' => $level,
                        'break_price' => $swing['price'],
                        'timestamp' => $swing['timestamp'],
                    ];

                    $signals[] = [
                        'timeframe' => '',
                        'engine' => 'market_structure',
                        'direction' => $direction,
                        'entry' => $level,
                        'sl' => $lastSwingHigh ? $lastSwingHigh['price'] : null,
                        'tp' => null,
                        'confluence_score' => $baseConfidence,
                        'candle_timestamp' => $swing['timestamp'],
                    ];

                    $trend = 'bearish';
                }
                $lastSwingLow = $swing;
            }
        }

        return [
            'signals' => $signals,
            'bos' => $bos,
            'trend' => $trend,
        ];
    }

    /**
     * Compute a simple Average True Range over the given period.
     */
    private function computeAtr(array $candles, int $period = 14): float
    {
        $count = count($candles);
        if ($count < 2) {
            return 0.0;
        }

        $trSum = 0.0;
        $start = max(1, $count - $period);
        $n = 0;

        for ($i = $start; $i < $count; $i++) {
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];
            $prevClose = (float) $candles[$i - 1]['close'];

            $tr = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
            $trSum += $tr;
            $n++;
        }

        return $n > 0 ? $trSum / $n : 0.0;
    }
}
