<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Trade;

class PaperTradeService
{
    /**
     * Execute a paper trade entry.
     */
    public function enter(int $userId, int $symbolId, string $type, float $entryPrice, float $quantity, ?float $sl = null, ?float $tp = null): Trade
    {
        return Trade::create([
            'user_id' => $userId,
            'symbol_id' => $symbolId,
            'type' => $type,
            'entry_price' => $entryPrice,
            'quantity' => $quantity,
            'sl' => $sl,
            'tp' => $tp,
            'status' => 'open',
        ]);
    }

    /**
     * Close a paper trade at given price.
     */
    public function exit(Trade $trade, float $exitPrice): Trade
    {
        $multiplier = $trade->type === 'long' ? 1 : -1;
        $pnl = ($exitPrice - $trade->entry_price) * $trade->quantity * $multiplier;

        $trade->update([
            'exit_price' => $exitPrice,
            'pnl' => $pnl,
            'status' => 'closed',
        ]);

        return $trade->fresh();
    }

    /**
     * Check if any open trades hit SL or TP at current price.
     */
    public function checkStops(float $currentPrice, int $symbolId): void
    {
        $openTrades = Trade::where('symbol_id', $symbolId)->open()->get();

        foreach ($openTrades as $trade) {
            if ($trade->sl && $this->isStopHit($trade, $currentPrice, 'sl')) {
                $this->exit($trade, $trade->sl);
            } elseif ($trade->tp && $this->isStopHit($trade, $currentPrice, 'tp')) {
                $this->exit($trade, $trade->tp);
            }
        }
    }

    private function isStopHit(Trade $trade, float $price, string $level): bool
    {
        if ($trade->type === 'long') {
            return $level === 'sl' ? $price <= $trade->sl : $price >= $trade->tp;
        }

        return $level === 'sl' ? $price >= $trade->sl : $price <= $trade->tp;
    }
}
