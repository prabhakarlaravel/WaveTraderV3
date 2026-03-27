<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id', 'timeframe', 'type', 'high', 'low',
        'formed_at', 'status', 'strength',
    ];

    protected $casts = [
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'formed_at' => 'datetime',
        'strength' => 'integer',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function scopeFresh($query)
    {
        return $query->where('status', 'fresh');
    }
}
