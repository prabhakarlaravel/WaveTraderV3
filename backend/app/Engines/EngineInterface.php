<?php

declare(strict_types=1);

namespace App\Engines;

interface EngineInterface
{
    public function run(array $candles, string $symbol, string $timeframe): EngineResult;
}
