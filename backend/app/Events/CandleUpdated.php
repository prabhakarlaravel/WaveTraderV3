<?php

declare(strict_types=1);

namespace App\Events;

use App\Traits\SanitizesChannelName;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class CandleUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SanitizesChannelName;

    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly array $candle,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('candles.' . static::sanitizeChannel($this->symbol) . ".{$this->timeframe}");
    }
}
