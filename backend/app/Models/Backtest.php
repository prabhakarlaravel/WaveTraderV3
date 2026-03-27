<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backtest extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id', 'timeframe', 'from_date', 'to_date',
        'mode', 'config', 'results_json',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'config' => 'array',
        'results_json' => 'array',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}
