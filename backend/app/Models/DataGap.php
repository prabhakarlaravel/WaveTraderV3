<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataGap extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id', 'timeframe', 'gap_start', 'gap_end', 'filled_at',
    ];

    protected $casts = [
        'gap_start' => 'datetime',
        'gap_end' => 'datetime',
        'filled_at' => 'datetime',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function scopeUnfilled($query)
    {
        return $query->whereNull('filled_at');
    }
}
