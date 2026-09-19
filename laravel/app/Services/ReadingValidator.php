<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Carbon\Carbon;

/**
 * Wire-level (schema) validation performed by the HTTP API before anything
 * reaches the queue. Mirrors the original backend validation bounds; the
 * worker applies strict sensor/range validation and quality flagging downstream.
 */
class ReadingValidator
{
    public static function validate(array $reading, Carbon $now): void
    {
        $s = $reading['sensors'];

        $checks = [
            ['temperature_c', -100.0, 100.0],
            ['humidity_pct', 0.0, 100.0],
            ['pressure_hpa', 300.0, 1200.0],
            ['windspeed_ms', 0.0, 200.0],
            ['wind_direction_deg', 0.0, 360.0],
        ];

        $problems = [];
        foreach ($checks as [$name, $lo, $hi]) {
            $value = $s[$name];
            if (! is_finite($value) || $value < $lo || $value > $hi) {
                $problems[] = sprintf('%s out of bounds [%s, %s]', $name, $lo, $hi);
            }
        }

        if ($s['rain_counter'] < 0) {
            $problems[] = 'rain_counter cannot be negative';
        }

        $ageSeconds = $now->getTimestamp() - $reading['taken_at']->getTimestamp();
        if ($ageSeconds < -300) {
            $problems[] = 'taken_at is too far in the future';
        }
        if ($ageSeconds > 7 * 24 * 3600) {
            $problems[] = 'taken_at is older than 7 days';
        }

        if ($problems) {
            throw ApiException::badRequest(implode('; ', $problems));
        }
    }
}