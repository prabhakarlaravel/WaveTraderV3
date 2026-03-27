<?php

declare(strict_types=1);

namespace App\Services\DataSources;

use Carbon\Carbon;
use Illuminate\Support\Collection;

interface DataSourceInterface
{
    public function fetchCandles(string $symbol, string $timeframe, Carbon $from, Carbon $to): Collection;

    public function supportsRealtime(): bool;

    public function getName(): string;
}
