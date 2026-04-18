<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Sanitizes symbol tickers for use in Reverb/Pusher broadcast channel names.
 *
 * Reverb and Pusher channel names cannot contain spaces.
 * Symbols like "NIFTY BANK" become "nifty-bank".
 */
trait SanitizesChannelName
{
    protected static function sanitizeChannel(string $symbol): string
    {
        return str_replace(' ', '-', strtolower($symbol));
    }
}
