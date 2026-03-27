<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends Model
{
    use HasFactory;

    protected $fillable = [
        'exchange', 'ticker', 'name', 'type', 'session',
        'timezone', 'lot_size', 'tick_size', 'active',
    ];

    protected $casts = [
        'lot_size' => 'decimal:4',
        'tick_size' => 'decimal:6',
        'active' => 'boolean',
    ];

    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }

    public function waves(): HasMany
    {
        return $this->hasMany(Wave::class);
    }

    public function orderBlocks(): HasMany
    {
        return $this->hasMany(OrderBlock::class);
    }

    public function fvgs(): HasMany
    {
        return $this->hasMany(FVG::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function dataGaps(): HasMany
    {
        return $this->hasMany(DataGap::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForExchange($query, string $exchange)
    {
        return $query->where('exchange', $exchange);
    }
}
