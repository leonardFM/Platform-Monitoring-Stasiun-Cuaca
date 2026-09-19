<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Recomputation pass: rebuilds the most recent hour/day aggregates directly
 * from the base telemetry_readings, self-healing any incremental drift.
 * Mirrors the original worker's 300s recalc loop.
 */
class AggregateRecalculator
{
    public function recalculate(): void
    {
        $this->recalculateGranularity('hour', '48 hours');
        $this->recalculateGranularity('day', '2 days');
    }

    private function recalculateGranularity(string $granularity, string $window): void
    {
        $granularity = match ($granularity) {
            'hour' => 'hour',
            'day' => 'day',
            default => throw new \InvalidArgumentException("unsupported granularity: {$granularity}"),
        };

        DB::update(
            "INSERT INTO station_aggregates (
                device_id, granularity, period_start, count,
                temperature_avg, temperature_min, temperature_max,
                humidity_avg, humidity_min, humidity_max,
                pressure_avg, pressure_min, pressure_max,
                windspeed_avg, windspeed_min, windspeed_max,
                wind_direction_avg, rain_total_mm
            )
            SELECT
                s.device_id,
                '{$granularity}',
                date_trunc('{$granularity}', s.taken_at),
                COUNT(*)::BIGINT,
                AVG(s.temperature_c), MIN(s.temperature_c), MAX(s.temperature_c),
                AVG(s.humidity_pct), MIN(s.humidity_pct), MAX(s.humidity_pct),
                AVG(s.pressure_hpa), MIN(s.pressure_hpa), MAX(s.pressure_hpa),
                AVG(s.windspeed_ms), MIN(s.windspeed_ms), MAX(s.windspeed_ms),
                AVG(s.wind_direction_deg),
                SUM(s.rain_delta_mm)
            FROM telemetry_readings s
            WHERE s.taken_at >= now() - ?::interval
            GROUP BY s.device_id, date_trunc('{$granularity}', s.taken_at)
            ON CONFLICT (device_id, granularity, period_start) DO UPDATE SET
                count = excluded.count,
                temperature_avg = excluded.temperature_avg,
                temperature_min = excluded.temperature_min,
                temperature_max = excluded.temperature_max,
                humidity_avg = excluded.humidity_avg,
                humidity_min = excluded.humidity_min,
                humidity_max = excluded.humidity_max,
                pressure_avg = excluded.pressure_avg,
                pressure_min = excluded.pressure_min,
                pressure_max = excluded.pressure_max,
                windspeed_avg = excluded.windspeed_avg,
                windspeed_min = excluded.windspeed_min,
                windspeed_max = excluded.windspeed_max,
                wind_direction_avg = excluded.wind_direction_avg,
                rain_total_mm = excluded.rain_total_mm",
            [
                $window,
            ],
        );
    }
}