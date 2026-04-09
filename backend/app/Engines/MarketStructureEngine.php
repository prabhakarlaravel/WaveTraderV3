<?php

declare(strict_types=1);

namespace App\Engines;

class MarketStructureEngine implements EngineInterface
{
    private const LOOKBACK_MAP = [
        '1M' => 3, '5M' => 4, '15M' => 5, '1H' => 8, '4H' => 12, '1D' => 20,
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

        return new EngineResult(
            engine: 'market_structure',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: $structures['signals'],
            overlays: [
                'swings' => $swings,
                'bos' => $structures['bos'],
            ],
            metadata: [
                'swing_count' => count($swings),
                'bos_count' => count($structures['bos']),
                'trend' => $structures['trend'],
            ],
        );
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
