<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wave extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id', 'timeframe', 'degree', 'wave_number',
        'start_time', 'end_time', 'start_price', 'end_price',
        'health_score', 'alternate',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'start_price' => 'decimal:8',
        'end_price' => 'decimal:8',
        'health_score' => 'integer',
        'alternate' => 'boolean',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function scopeForSymbol($query, int $symbolId, string $timeframe)
    {
        return $query->where('symbol_id', $symbolId)->where('timeframe', $timeframe);
    }

    public function scopePrimary($query)
    {
        return $query->where('alternate', false);
    }
}
