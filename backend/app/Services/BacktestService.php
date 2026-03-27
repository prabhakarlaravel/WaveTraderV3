<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\FVGEngine;
use App\Engines\MarketStructureEngine;
use App\Engines\OrderBlockEngine;
use App\Engines\PriceActionEngine;
use App\Models\Backtest;
use App\Models\Candle;
use App\Models\Symbol;

class BacktestService
{
    /**
     * Run a bar-by-bar backtest.
     * In 'auto' mode: engines generate signals → system takes trades.
     * Returns full P&L report with trade log and equity curve.
     */
    public function run(Backtest $backtest): array
    {
        $symbol = Symbol::findOrFail($backtest->symbol_id);
        $candles = Candle::where('symbol_id', $backtest->symbol_id)
            ->where('timeframe', $backtest->timeframe)
            ->whereBetween('timestamp', [$backtest->from_date, $backtest->to_date])
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        if (count($candles) < 30) {
            return $this->emptyResult(0);
        }

        $config = $backtest->config ?? [];
        $riskPct = $config['risk_pct'] ?? 1.0;
        $maxPositions = $config['max_positions'] ?? 3;
        $initialCapital = $config['initial_capital'] ?? 10000;

        $engines = [
            new MarketStructureEngine(5),
            new OrderBlockEngine(),
            new FVGEngine(),
            new PriceActionEngine(),
        ];

        $capital = $initialCapital;
        $equity = [$initialCapital];
        $trades = [];
        $openPositions = [];
        $peakEquity = $initialCapital;
        $maxDrawdown = 0;

        // Bar-by-bar replay
        for ($i = 30; $i < count($candles); $i++) {
            $window = array_slice($candles, 0, $i + 1);
            $currentBar = $candles[$i];
            $currentPrice = (float) $currentBar['close'];

            // Check stops on open positions
            foreach ($openPositions as $key => &$pos) {
                $hit = $this->checkStopHit($pos, $currentBar);
                if ($hit) {
                    $pnl = $this->calculatePnl($pos, $hit['price']);
                    $capital += $pnl;
                    $trades[] = [
                        'entry_time' => $pos['entry_time'],
                        'exit_time' => $currentBar['timestamp'],
                        'direction' => $pos['direction'],
                        'entry' => $pos['entry'],
                        'exit' => $hit['price'],
                        'pnl' => round($pnl, 2),
                        'reason' => $hit['reason'],
                        'engine' => $pos['engine'],
                    ];
                    unset($openPositions[$key]);
                }
            }
            unset($pos);
            $openPositions = array_values($openPositions);

            // Auto mode: run engines and take trades from signals
            if ($backtest->mode === 'auto' && count($openPositions) < $maxPositions) {
                foreach ($engines as $engine) {
                    $result = $engine->run($window, $symbol->ticker, $backtest->timeframe);

                    if (! $result->hasSignals()) {
                        continue;
                    }

                    // Take the most recent signal
                    $signals = $result->signals;
                    $signal = end($signals);
                    if (! $signal || ! isset($signal['direction'])) {
                        continue;
                    }

                    // Only take signals from the current bar
                    if (($signal['candle_timestamp'] ?? '') !== $currentBar['timestamp']) {
                        continue;
                    }

                    $direction = $signal['direction'];
                    if ($direction === 'neutral') {
                        continue;
                    }

                    // Check for duplicate direction
                    $alreadyOpen = false;
                    foreach ($openPositions as $p) {
                        if ($p['direction'] === $direction) {
                            $alreadyOpen = true;
                            break;
                        }
                    }
                    if ($alreadyOpen) {
                        continue;
                    }

                    $positionSize = ($capital * $riskPct / 100);
                    $sl = isset($signal['sl']) ? (float) $signal['sl'] : null;
                    $tp = isset($signal['tp']) ? (float) $signal['tp'] : null;

                    // Default SL/TP if not provided
                    $atr = $this->simpleATR($window, 14);
                    if (! $sl) {
                        $sl = $direction === 'buy'
                            ? $currentPrice - $atr * 2
                            : $currentPrice + $atr * 2;
                    }
                    if (! $tp) {
                        $tp = $direction === 'buy'
                            ? $currentPrice + $atr * 3
                            : $currentPrice - $atr * 3;
                    }

                    $openPositions[] = [
                        'direction' => $direction,
                        'entry' => $currentPrice,
                        'sl' => $sl,
                        'tp' => $tp,
                        'size' => $positionSize,
                        'entry_time' => $currentBar['timestamp'],
                        'engine' => $signal['engine'] ?? $result->engine,
                    ];

                    if (count($openPositions) >= $maxPositions) {
                        break;
                    }
                }
            }

            // Track equity
            $unrealizedPnl = 0;
            foreach ($openPositions as $pos) {
                $unrealizedPnl += $this->calculatePnl($pos, $currentPrice);
            }
            $currentEquity = $capital + $unrealizedPnl;
            $equity[] = round($currentEquity, 2);

            if ($currentEquity > $peakEquity) {
                $peakEquity = $currentEquity;
            }
            $drawdown = $peakEquity > 0 ? ($peakEquity - $currentEquity) / $peakEquity * 100 : 0;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }

        // Close remaining positions at last bar price
        $lastPrice = (float) end($candles)['close'];
        foreach ($openPositions as $pos) {
            $pnl = $this->calculatePnl($pos, $lastPrice);
            $capital += $pnl;
            $trades[] = [
                'entry_time' => $pos['entry_time'],
                'exit_time' => end($candles)['timestamp'],
                'direction' => $pos['direction'],
                'entry' => $pos['entry'],
                'exit' => $lastPrice,
                'pnl' => round($pnl, 2),
                'reason' => 'end_of_backtest',
                'engine' => $pos['engine'],
            ];
        }

        return $this->buildReport($trades, $equity, $initialCapital, $capital, $maxDrawdown, count($candles));
    }

    private function checkStopHit(array $pos, array $bar): ?array
    {
        $high = (float) $bar['high'];
        $low = (float) $bar['low'];

        if ($pos['direction'] === 'buy') {
            if ($pos['sl'] && $low <= $pos['sl']) {
                return ['price' => $pos['sl'], 'reason' => 'stop_loss'];
            }
            if ($pos['tp'] && $high >= $pos['tp']) {
                return ['price' => $pos['tp'], 'reason' => 'take_profit'];
            }
        } else {
            if ($pos['sl'] && $high >= $pos['sl']) {
                return ['price' => $pos['sl'], 'reason' => 'stop_loss'];
            }
            if ($pos['tp'] && $low <= $pos['tp']) {
                return ['price' => $pos['tp'], 'reason' => 'take_profit'];
            }
        }

        return null;
    }

    private function calculatePnl(array $pos, float $exitPrice): float
    {
        $multiplier = $pos['direction'] === 'buy' ? 1 : -1;
        $priceDiff = ($exitPrice - $pos['entry']) * $multiplier;
        $pctReturn = $pos['entry'] > 0 ? $priceDiff / $pos['entry'] : 0;

        return $pos['size'] * $pctReturn;
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

    private function buildReport(array $trades, array $equity, float $initialCapital, float $finalCapital, float $maxDrawdown, int $totalBars): array
    {
        $totalTrades = count($trades);
        $wins = array_filter($trades, fn ($t) => $t['pnl'] > 0);
        $losses = array_filter($trades, fn ($t) => $t['pnl'] <= 0);

        $winCount = count($wins);
        $lossCount = count($losses);
        $winRate = $totalTrades > 0 ? round($winCount / $totalTrades * 100, 1) : 0;

        $grossProfit = array_sum(array_column($wins, 'pnl'));
        $grossLoss = abs(array_sum(array_column($losses, 'pnl')));
        $profitFactor = $grossLoss > 0 ? round($grossProfit / $grossLoss, 2) : ($grossProfit > 0 ? 999 : 0);

        $netPnl = round($finalCapital - $initialCapital, 2);
        $returnPct = round($netPnl / $initialCapital * 100, 2);

        // Sharpe ratio (simplified: annualized from daily returns)
        $returns = [];
        for ($i = 1; $i < count($equity); $i++) {
            if ($equity[$i - 1] > 0) {
                $returns[] = ($equity[$i] - $equity[$i - 1]) / $equity[$i - 1];
            }
        }
        $avgReturn = count($returns) > 0 ? array_sum($returns) / count($returns) : 0;
        $stdReturn = 0;
        if (count($returns) > 1) {
            $variance = array_sum(array_map(fn ($r) => ($r - $avgReturn) ** 2, $returns)) / (count($returns) - 1);
            $stdReturn = sqrt($variance);
        }
        $sharpe = $stdReturn > 0 ? round($avgReturn / $stdReturn * sqrt(252), 2) : 0;

        // Average RRR
        $avgWin = $winCount > 0 ? $grossProfit / $winCount : 0;
        $avgLoss = $lossCount > 0 ? $grossLoss / $lossCount : 1;
        $avgRrr = $avgLoss > 0 ? round($avgWin / $avgLoss, 2) : 0;

        // Consecutive wins/losses
        $maxConsecWins = $this->maxConsecutive($trades, true);
        $maxConsecLosses = $this->maxConsecutive($trades, false);

        // Sample equity curve (max 200 points)
        $step = max(1, (int) floor(count($equity) / 200));
        $equityCurve = [];
        for ($i = 0; $i < count($equity); $i += $step) {
            $equityCurve[] = $equity[$i];
        }
        $equityCurve[] = end($equity);

        // Trade distribution by engine
        $byEngine = [];
        foreach ($trades as $t) {
            $eng = $t['engine'] ?? 'unknown';
            if (! isset($byEngine[$eng])) {
                $byEngine[$eng] = ['count' => 0, 'pnl' => 0, 'wins' => 0];
            }
            $byEngine[$eng]['count']++;
            $byEngine[$eng]['pnl'] += $t['pnl'];
            if ($t['pnl'] > 0) {
                $byEngine[$eng]['wins']++;
            }
        }

        return [
            'total_bars' => $totalBars,
            'total_trades' => $totalTrades,
            'net_pnl' => $netPnl,
            'return_pct' => $returnPct,
            'win_rate' => $winRate,
            'profit_factor' => $profitFactor,
            'sharpe_ratio' => $sharpe,
            'max_drawdown' => round($maxDrawdown, 2),
            'avg_rrr' => $avgRrr,
            'max_consec_wins' => $maxConsecWins,
            'max_consec_losses' => $maxConsecLosses,
            'initial_capital' => $initialCapital,
            'final_capital' => round($finalCapital, 2),
            'equity_curve' => $equityCurve,
            'trades' => $trades,
            'by_engine' => $byEngine,
        ];
    }

    private function maxConsecutive(array $trades, bool $wins): int
    {
        $max = 0;
        $current = 0;
        foreach ($trades as $t) {
            if (($wins && $t['pnl'] > 0) || (! $wins && $t['pnl'] <= 0)) {
                $current++;
                $max = max($max, $current);
            } else {
                $current = 0;
            }
        }

        return $max;
    }

    private function emptyResult(int $bars): array
    {
        return [
            'total_bars' => $bars,
            'total_trades' => 0,
            'net_pnl' => 0,
            'return_pct' => 0,
            'win_rate' => 0,
            'profit_factor' => 0,
            'sharpe_ratio' => 0,
            'max_drawdown' => 0,
            'avg_rrr' => 0,
            'max_consec_wins' => 0,
            'max_consec_losses' => 0,
            'initial_capital' => 10000,
            'final_capital' => 10000,
            'equity_curve' => [],
            'trades' => [],
            'by_engine' => [],
        ];
    }
}
