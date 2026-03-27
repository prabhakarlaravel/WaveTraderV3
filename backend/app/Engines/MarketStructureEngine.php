<?php

declare(strict_types=1);

namespace App\Engines;

class MarketStructureEngine implements EngineInterface
{
    private int $lookback;

    public function __construct(int $lookback = 5)
    {
        $this->lookback = $lookback;
    }

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < $this->lookback * 2 + 1) {
            return new EngineResult(engine: 'market_structure', symbol: $symbol, timeframe: $timeframe);
        }

        $swings = $this->detectSwings($candles);
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
    private function detectSwings(array $candles): array
    {
        $swings = [];
        $n = $this->lookback;

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
     */
    private function detectBosChoch(array $swings, array $candles): array
    {
        $signals = [];
        $bos = [];
        $lastSwingHigh = null;
        $lastSwingLow = null;
        $trend = 'neutral'; // 'bullish', 'bearish', 'neutral'

        foreach ($swings as $swing) {
            if ($swing['type'] === 'high') {
                if ($lastSwingHigh !== null && $swing['price'] > $lastSwingHigh['price']) {
                    // Bullish BOS — higher high
                    $type = $trend === 'bearish' ? 'choch' : 'bos';
                    $direction = 'buy';

                    $bos[] = [
                        'type' => $type,
                        'direction' => $direction,
                        'price' => $lastSwingHigh['price'],
                        'break_price' => $swing['price'],
                        'timestamp' => $swing['timestamp'],
                    ];

                    $signals[] = [
                        'timeframe' => '', // filled by caller
                        'engine' => 'market_structure',
                        'direction' => $direction,
                        'entry' => $lastSwingHigh['price'],
                        'sl' => $lastSwingLow ? $lastSwingLow['price'] : null,
                        'tp' => null,
                        'confluence_score' => $type === 'choch' ? 80 : 60,
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

                    $bos[] = [
                        'type' => $type,
                        'direction' => $direction,
                        'price' => $lastSwingLow['price'],
                        'break_price' => $swing['price'],
                        'timestamp' => $swing['timestamp'],
                    ];

                    $signals[] = [
                        'timeframe' => '',
                        'engine' => 'market_structure',
                        'direction' => $direction,
                        'entry' => $lastSwingLow['price'],
                        'sl' => $lastSwingHigh ? $lastSwingHigh['price'] : null,
                        'tp' => null,
                        'confluence_score' => $type === 'choch' ? 80 : 60,
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
}
