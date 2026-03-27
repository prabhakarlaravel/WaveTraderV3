<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'symbol_id', 'type', 'entry_price', 'exit_price',
        'quantity', 'sl', 'tp', 'engine', 'timeframe', 'wave_position',
        'confluence_score', 'status', 'pnl', 'notes', 'tags', 'auto_trade',
    ];

    protected $casts = [
        'entry_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'sl' => 'decimal:8',
        'tp' => 'decimal:8',
        'pnl' => 'decimal:8',
        'tags' => 'array',
        'auto_trade' => 'boolean',
        'confluence_score' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
