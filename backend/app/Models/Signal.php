<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id', 'timeframe', 'engine', 'direction',
        'entry', 'sl', 'tp', 'confluence_score', 'candle_timestamp',
    ];

    protected $casts = [
        'entry' => 'decimal:8',
        'sl' => 'decimal:8',
        'tp' => 'decimal:8',
        'confluence_score' => 'integer',
        'candle_timestamp' => 'datetime',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}
