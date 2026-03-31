<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OverlaysUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly array $overlays,
        public readonly string $computedAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("overlays.{$this->symbol}.{$this->timeframe}");
    }
}
