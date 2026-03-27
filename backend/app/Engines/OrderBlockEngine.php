<?php

declare(strict_types=1);

namespace App\Engines;

class OrderBlockEngine implements EngineInterface
{
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

        $atr = $this->calculateATR($candles, $this->atrPeriod);
        $orderBlocks = $this->detectOrderBlocks($candles, $atr);
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

    private function detectOrderBlocks(array $candles, array $atr): array
    {
        $orderBlocks = [];

        for ($i = 2; $i < count($candles); $i++) {
            if (! isset($atr[$i])) {
                continue;
            }

            $curr = $candles[$i];
            $prev = $candles[$i - 1];
            $threshold = $atr[$i] * $this->impulseMultiplier;

            $currBody = abs((float) $curr['close'] - (float) $curr['open']);
            $currDirection = (float) $curr['close'] > (float) $curr['open'] ? 'bullish' : 'bearish';
            $prevDirection = (float) $prev['close'] > (float) $prev['open'] ? 'bullish' : 'bearish';

            // Bullish OB: bearish candle followed by strong bullish impulse
            if ($currDirection === 'bullish' && $prevDirection === 'bearish' && $currBody > $threshold) {
                $orderBlocks[] = [
                    'type' => 'bullish',
                    'high' => (float) $prev['high'],
                    'low' => (float) $prev['low'],
                    'formed_at' => $prev['timestamp'],
                    'status' => 'fresh',
                    'strength' => min(100, (int) (($currBody / $atr[$i]) * 40)),
                    'timeframe' => '', // filled by caller
                ];
            }

            // Bearish OB: bullish candle followed by strong bearish impulse
            if ($currDirection === 'bearish' && $prevDirection === 'bullish' && $currBody > $threshold) {
                $orderBlocks[] = [
                    'type' => 'bearish',
                    'high' => (float) $prev['high'],
                    'low' => (float) $prev['low'],
                    'formed_at' => $prev['timestamp'],
                    'status' => 'fresh',
                    'strength' => min(100, (int) (($currBody / $atr[$i]) * 40)),
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
