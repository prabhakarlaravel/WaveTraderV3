<?php

declare(strict_types=1);

namespace App\Events;

use App\Traits\SanitizesChannelName;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class WaveUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SanitizesChannelName;

    public function __construct(
        public readonly string $symbol,
        public readonly array $waves,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('waves.' . static::sanitizeChannel($this->symbol));
    }
}
