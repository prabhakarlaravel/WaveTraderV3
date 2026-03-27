<?php

declare(strict_types=1);

namespace App\Engines;

use Carbon\Carbon;

class EngineResult
{
    public function __construct(
        public readonly string $engine,
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly array $signals = [],
        public readonly array $overlays = [],
        public readonly array $metadata = [],
        public readonly Carbon $timestamp = new Carbon(),
    ) {}

    public function hasSignals(): bool
    {
        return ! empty($this->signals);
    }
}
