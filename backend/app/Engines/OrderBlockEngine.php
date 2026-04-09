<?php

declare(strict_types=1);

namespace App\Engines;

class OrderBlockEngine implements EngineInterface
{
    private const IMPULSE_MULT_MAP = [
        '1M' => 1.2, '5M' => 1.3, '15M' => 1.5, '1H' => 1.6, '4H' => 1.8, '1D' => 2.0,
    ];

    private int $atrPeriod;
    private float $impulseMultiplier;

    public function __construct(int $atrPeriod = 14, float $impulseMultiplier = 1.5)
    {
        $this->atrPeriod = $atrPeriod;
        $this->impulseMultiplier = $impulseMultiplier;
    }

    public function run(array $candles, string $symbol, string $timeframe): EngineResult
    {
        if (count($candles) < $this->atrPeriod + 5) {
            return new EngineResult(engine: 'order_block', symbol: $symbol, timeframe: $timeframe);
        }

        $multiplier = self::IMPULSE_MULT_MAP[$timeframe] ?? $this->impulseMultiplier;
        $atr = $this->calculateATR($candles, $this->atrPeriod);
        $orderBlocks = $this->detectOrderBlocks($candles, $atr, $multiplier);
        $this->updateMitigationStatus($orderBlocks, $candles);

        return new EngineResult(
            engine: 'order_block',
            symbol: $symbol,
            timeframe: $timeframe,
            signals: [],
            overlays: ['orderBlocks' => $orderBlocks],
            metadata: [
                'total' => count($orderBlocks),
                'fresh' => count(array_filter($orderBlocks, fn ($ob) => $ob['status'] === 'fresh')),
            ],
        );
    }

    private function detectOrderBlocks(array $candles, array $atr, float $multiplier): array
    {
        $orderBlocks = [];
        $candleCount = count($candles);

        for ($i = 2; $i < $candleCount; $i++) {
            if (! isset($atr[$i])) {
                continue;
            }

            $curr = $candles[$i];
            $prev = $candles[$i - 1];
            $threshold = $atr[$i] * $multiplier;

            $currBody = abs((float) $curr['close'] - (float) $curr['open']);
            $currDirection = (float) $curr['close'] > (float) $curr['open'] ? 'bullish' : 'bearish';
            $prevDirection = (float) $prev['close'] > (float) $prev['open'] ? 'bullish' : 'bearish';

            // Bullish OB: bearish candle followed by strong bullish impulse
            if ($currDirection === 'bullish' && $prevDirection === 'bearish' && $currBody > $threshold) {
                // Require at least 1 continuation candle in the same direction
                if (! isset($candles[$i + 1]) || (float) $candles[$i + 1]['close'] <= (float) $candles[$i + 1]['open']) {
                    continue;
                }

                $strength = min(100, (int) (($currBody / $atr[$i]) * 40));

                // Boost strength by 15 if 2 consecutive continuation candles exist
                if (isset($candles[$i + 2]) && (float) $candles[$i + 2]['close'] > (float) $candles[$i + 2]['open']) {
                    $strength = min(100, $strength + 15);
                }

                $orderBlocks[] = [
                    'type' => 'bullish',
                    'high' => (float) $prev['high'],
                    'low' => (float) $prev['low'],
                    'formed_at' => $prev['timestamp'],
                    'status' => 'fresh',
                    'strength' => $strength,
                    'timeframe' => '', // filled by caller
                ];
            }

            // Bearish OB: bullish candle followed by strong bearish impulse
            if ($currDirection === 'bearish' && $prevDirection === 'bullish' && $currBody > $threshold) {
                // Require at least 1 continuation candle in the same direction
                if (! isset($candles[$i + 1]) || (float) $candles[$i + 1]['close'] >= (float) $candles[$i + 1]['open']) {
                    continue;
                }

                $strength = min(100, (int) (($currBody / $atr[$i]) * 40));

                // Boost strength by 15 if 2 consecutive continuation candles exist
                if (isset($candles[$i + 2]) && (float) $candles[$i + 2]['close'] < (float) $candles[$i + 2]['open']) {
                    $strength = min(100, $strength + 15);
                }

                $orderBlocks[] = [
                    'type' => 'bearish',
                    'high' => (float) $prev['high'],
                    'low' => (float) $prev['low'],
                    'formed_at' => $prev['timestamp'],
                    'status' => 'fresh',
                    'strength' => $strength,
                    'timeframe' => '',
                ];
            }
        }

        return $orderBlocks;
    }

    private function updateMitigationStatus(array &$orderBlocks, array $candles): void
    {
        foreach ($orderBlocks as &$ob) {
            $formedIndex = null;
            foreach ($candles as $idx => $c) {
                if ($c['timestamp'] === $ob['formed_at']) {
                    $formedIndex = $idx;
                    break;
                }
            }

            if ($formedIndex === null) {
                continue;
            }

            // Check candles after formation
            for ($i = $formedIndex + 2; $i < count($candles); $i++) {
                $close = (float) $candles[$i]['close'];
                $low = (float) $candles[$i]['low'];
                $high = (float) $candles[$i]['high'];

                if ($ob['type'] === 'bullish') {
                    if ($close < $ob['low']) {
                        $ob['status'] = 'fully_mitigated';
                        break;
                    }
                    if ($low <= $ob['high']) {
                        $ob['status'] = 'partially_mitigated';
                    }
                } else {
                    if ($close > $ob['high']) {
                        $ob['status'] = 'fully_mitigated';
                        break;
                    }
                    if ($high >= $ob['low']) {
                        $ob['status'] = 'partially_mitigated';
                    }
                }
            }
        }
    }

    private function calculateATR(array $candles, int $period): array
    {
        $atr = [];
        $trValues = [];

        for ($i = 1; $i < count($candles); $i++) {
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];
            $prevClose = (float) $candles[$i - 1]['close'];

            $tr = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
            $trValues[] = $tr;

            if (count($trValues) >= $period) {
                $atr[$i] = array_sum(array_slice($trValues, -$period)) / $period;
            }
        }

        return $atr;
    }
}
