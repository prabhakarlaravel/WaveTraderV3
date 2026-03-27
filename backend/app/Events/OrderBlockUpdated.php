<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OrderBlockUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $symbol,
        public readonly array $orderBlocks,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("signals.{$this->symbol}");
    }
}
