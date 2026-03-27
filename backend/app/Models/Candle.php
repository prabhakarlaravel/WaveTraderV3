<?php

declare(strict_types=1);

namespace App\Models;

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

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /**
     * Upsert candles using INSERT ... ON CONFLICT DO UPDATE (rule #2).
     */
    public static function upsertCandles(array $candles): void
    {
        if (empty($candles)) {
            return;
        }

        self::upsert(
            $candles,
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
