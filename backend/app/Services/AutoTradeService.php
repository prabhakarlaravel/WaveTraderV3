<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\ConfluenceEngine;
use App\Engines\ElliottWaveEngine;
use App\Engines\FVGEngine;
use App\Engines\MarketStructureEngine;
use App\Engines\OrderBlockEngine;
use App\Engines\PriceActionEngine;
use App\Engines\SMCEngine;
use App\Engines\VWAPEngine;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\Trade;
use Illuminate\Support\Facades\Log;

class AutoTradeService
{
    private int $minConfluence;
    private float $riskPct;
    private int $maxPositions;

    public function __construct(int $minConfluence = 60, float $riskPct = 1.0, int $maxPositions = 3)
    {
        $this->minConfluence = $minConfluence;
        $this->riskPct = $riskPct;
        $this->maxPositions = $maxPositions;
    }

    /**
     * Evaluate signals from all engines and auto-execute paper trades
     * when confluence score meets the threshold.
     */
    public function evaluate(int $userId, int $symbolId, string $timeframe): array
    {
        $symbol = Symbol::findOrFail($symbolId);
        $candles = Candle::where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (count($candles) < 50) {
            return ['action' => 'skip', 'reason' => 'insufficient data'];
        }

        $currentPrice = (float) end($candles)['close'];

        // Check open positions count
        $openCount = Trade::where('user_id', $userId)
            ->where('symbol_id', $symbolId)
            ->open()
            ->count();

        if ($openCount >= $this->maxPositions) {
            return ['action' => 'skip', 'reason' => 'max positions reached'];
        }

        // Run all engines
        $ew = (new ElliottWaveEngine())->run($candles, $symbol->ticker, $timeframe);
        $ms = (new MarketStructureEngine(5))->run($candles, $symbol->ticker, $timeframe);
        $ob = (new OrderBlockEngine())->run($candles, $symbol->ticker, $timeframe);
        $fvg = (new FVGEngine())->run($candles, $symbol->ticker, $timeframe);
        $smc = (new SMCEngine())->run($candles, $symbol->ticker, $timeframe);
        $vwap = (new VWAPEngine())->run($candles, $symbol->ticker, $timeframe);
        $pa = (new PriceActionEngine())->run($candles, $symbol->ticker, $timeframe);

        // Score confluence
        $confluence = (new ConfluenceEngine())->score($ew, $ms, $ob, $fvg, $smc, $vwap, $pa, $currentPrice);

        if ($confluence['pct'] < $this->minConfluence) {
            return [
                'action' => 'skip',
                'reason' => "confluence {$confluence['pct']}% < {$this->minConfluence}%",
                'confluence' => $confluence,
            ];
        }

        if ($confluence['direction'] === 'NEUTRAL') {
            return ['action' => 'skip', 'reason' => 'no clear direction', 'confluence' => $confluence];
        }

        // Calculate position sizing
        $atr = $this->simpleATR($candles, 14);
        $direction = $confluence['direction'] === 'BULL' ? 'long' : 'short';
        $sl = $direction === 'long' ? $currentPrice - $atr * 2 : $currentPrice + $atr * 2;
        $tp = $direction === 'long' ? $currentPrice + $atr * 3 : $currentPrice - $atr * 3;

        // Create trade
        $trade = Trade::create([
            'user_id' => $userId,
            'symbol_id' => $symbolId,
            'type' => $direction,
            'entry_price' => $currentPrice,
            'quantity' => 1,
            'sl' => round($sl, 2),
            'tp' => round($tp, 2),
            'engine' => $confluence['breakdown']['trigger']['desc'] ?? 'confluence',
            'timeframe' => $timeframe,
            'wave_position' => $ew->metadata['current_wave'] ?? null,
            'confluence_score' => $confluence['pct'],
            'status' => 'open',
            'auto_trade' => true,
            'tags' => ['auto', $confluence['direction'], $confluence['action']],
            'notes' => "Auto: {$confluence['action']} | Context: {$confluence['breakdown']['context']['desc']} | Levels: {$confluence['breakdown']['levels']['desc']}",
        ]);

        Log::info("AutoTrade: {$direction} {$symbol->ticker} @ {$currentPrice} | Confluence: {$confluence['pct']}%");

        return [
            'action' => 'trade_opened',
            'trade' => $trade,
            'confluence' => $confluence,
        ];
    }

    /**
     * Check and close trades that hit SL/TP.
     */
    public function checkStops(int $userId, int $symbolId): array
    {
        $candles = Candle::where('symbol_id', $symbolId)
            ->orderByDesc('timestamp')
            ->limit(1)
            ->first();

        if (! $candles) {
            return [];
        }

        $currentPrice = (float) $candles->close;
        $high = (float) $candles->high;
        $low = (float) $candles->low;

        $closedTrades = [];

        $openTrades = Trade::where('user_id', $userId)
            ->where('symbol_id', $symbolId)
            ->open()
            ->get();

        foreach ($openTrades as $trade) {
            $exitPrice = null;
            $reason = null;

            if ($trade->type === 'long') {
                if ($trade->sl && $low <= (float) $trade->sl) {
                    $exitPrice = (float) $trade->sl;
                    $reason = 'stop_loss';
                } elseif ($trade->tp && $high >= (float) $trade->tp) {
                    $exitPrice = (float) $trade->tp;
                    $reason = 'take_profit';
                }
            } else {
                if ($trade->sl && $high >= (float) $trade->sl) {
                    $exitPrice = (float) $trade->sl;
                    $reason = 'stop_loss';
                } elseif ($trade->tp && $low <= (float) $trade->tp) {
                    $exitPrice = (float) $trade->tp;
                    $reason = 'take_profit';
                }
            }

            if ($exitPrice !== null) {
                $multiplier = $trade->type === 'long' ? 1 : -1;
                $pnl = ($exitPrice - (float) $trade->entry_price) * (float) $trade->quantity * $multiplier;

                $trade->update([
                    'exit_price' => $exitPrice,
                    'pnl' => round($pnl, 8),
                    'status' => 'closed',
                    'notes' => ($trade->notes ? $trade->notes . ' | ' : '') . "Closed: {$reason} @ {$exitPrice}",
                ]);

                $closedTrades[] = $trade;
            }
        }

        return $closedTrades;
    }

    private function simpleATR(array $candles, int $period): float
    {
        $trValues = [];
        for ($i = max(1, count($candles) - $period); $i < count($candles); $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $pc = (float) $candles[$i - 1]['close'];
            $trValues[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        return count($trValues) > 0 ? array_sum($trValues) / count($trValues) : 0;
    }
}
