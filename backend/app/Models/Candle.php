<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candle extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'symbol_id', 'timeframe', 'timestamp',
        'open', 'high', 'low', 'close', 'volume',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'open' => 'decimal:8',
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'close' => 'decimal:8',
        'volume' => 'decimal:8',
    ];

    /** Bucket size in seconds per timeframe — used to floor timestamps. */
    private const TIMEFRAME_SECONDS = [
        '1M'  => 60,
        '5M'  => 300,
        '15M' => 900,
        '1H'  => 3600,
        '4H'  => 14400,
        '1D'  => 86400,
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /**
     * Floor a timestamp down to the start of its timeframe bucket.
     * Guarantees candles land on proper bucket boundaries (e.g. 1M → HH:MM:00)
     * even if the upstream data source provides sub-second precision or
     * misaligned ticks.
     */
    public static function floorTimestamp(string $timeframe, string|\DateTimeInterface $timestamp): string
    {
        $bucket = self::TIMEFRAME_SECONDS[$timeframe] ?? 60;
        $ts = $timestamp instanceof \DateTimeInterface
            ? Carbon::instance($timestamp)
            : Carbon::parse($timestamp);
        $epoch = $ts->getTimestamp();
        $floored = intdiv($epoch, $bucket) * $bucket;

        return Carbon::createFromTimestamp($floored, 'UTC')->toDateTimeString();
    }

    /**
     * Upsert candles using INSERT ... ON CONFLICT DO UPDATE (rule #2).
     *
     * All timestamps are floored to the start of their timeframe bucket so
     * repeated polls of the same forming candle collapse on the primary key
     * and duplicate sub-minute rows can never accumulate.
     */
    public static function upsertCandles(array $candles): void
    {
        if (empty($candles)) {
            return;
        }

        // Floor every timestamp to its timeframe bucket and merge duplicates
        // in-batch (last-write-wins on OHLC, max/sum is enforced by upsert
        // ordering — Postgres takes the final row from the input set).
        $deduped = [];
        foreach ($candles as $c) {
            $tf = $c['timeframe'] ?? '1M';
            $c['timestamp'] = self::floorTimestamp($tf, $c['timestamp']);
            $key = ($c['symbol_id'] ?? 0) . '|' . $tf . '|' . $c['timestamp'];
            if (isset($deduped[$key])) {
                // Merge tick into bucket: open stays, high=max, low=min,
                // close=latest, volume=sum.
                $prev = $deduped[$key];
                $c['open']   = $prev['open'];
                $c['high']   = max((float) $prev['high'], (float) $c['high']);
                $c['low']    = min((float) $prev['low'], (float) $c['low']);
                $c['volume'] = (float) $prev['volume'] + (float) $c['volume'];
            }
            $deduped[$key] = $c;
        }

        self::upsert(
            array_values($deduped),
            ['symbol_id', 'timeframe', 'timestamp'],
            ['open', 'high', 'low', 'close', 'volume']
        );
    }

    public function scopeForSymbol($query, int $symbolId, string $timeframe)
    {
        return $query->where('symbol_id', $symbolId)->where('timeframe', $timeframe);
    }

    public function scopeInRange($query, string $from, string $to)
    {
        return $query->whereBetween('timestamp', [$from, $to]);
    }
}
