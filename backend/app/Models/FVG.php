<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FVG extends Model
{
    use HasFactory;

    protected $table = 'fvgs';

    protected $fillable = [
        'symbol_id', 'timeframe', 'type', 'high', 'low',
        'formed_at', 'fill_pct',
    ];

    protected $casts = [
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'formed_at' => 'datetime',
        'fill_pct' => 'decimal:2',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function scopeUnfilled($query)
    {
        return $query->where('fill_pct', '<', 100);
    }
}
