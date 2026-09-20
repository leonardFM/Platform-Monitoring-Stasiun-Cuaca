<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Recomputation pass: rebuilds the most recent hour/day reading_aggregates
 * directly from base sensor_readings, self-healing any incremental drift.
 * Mirrors the scheduler's 300s recalc loop (routes/console.php).
 */
class AggregateRecalculator
{
    public function recalculate(): void
    {
        $this->recalculateGranularity('1h', '48 hours');
        $this->recalculateGranularity('1d', '4 days');
    }

    private function recalculateGranularity(string $granularity, string $window): void
    {
        if (! in_array($granularity, ['1m', '1h', '1d'], true)) {
            throw new \InvalidArgumentException("unsupported granularity: {$granularity}");
        }

        // Granularity di-allowlist di atas sehingga aman di-interpolasi;
        // bind param tidak bisa dipakai di date_trunc(...) pada GROUP BY.
        $unit = match ($granularity) {
            '1m' => 'minute',
            '1h' => 'hour',
            '1d' => 'day',
        };
        $bucket = "date_trunc('{$unit}', s.device_time)";

        DB::update(
            "INSERT INTO reading_aggregates (
                sensor_id, \"interval\", bucket_start,
                min_value, max_value, avg_value, sum_value,
                sample_count, quality_count
            )
            SELECT
                s.sensor_id,
                ?::VARCHAR,
                {$bucket},
                MIN(COALESCE(s.corrected_value, s.raw_value)),
                MAX(COALESCE(s.corrected_value, s.raw_value)),
                AVG(COALESCE(s.corrected_value, s.raw_value)),
                SUM(COALESCE(s.corrected_value, s.raw_value)),
                COUNT(*)::INTEGER,
                COUNT(*) FILTER (WHERE s.quality_flag = 'GOOD')::INTEGER
            FROM sensor_readings s
            JOIN sensors sen ON sen.id = s.sensor_id
            JOIN sensor_types st ON st.id = sen.sensor_type_id AND st.code <> 'rain_counter'
            WHERE s.device_time >= now() - ?::INTERVAL
            GROUP BY s.sensor_id, {$bucket}
            ON CONFLICT (sensor_id, \"interval\", bucket_start) DO UPDATE SET
                min_value = excluded.min_value,
                max_value = excluded.max_value,
                avg_value = excluded.avg_value,
                sum_value = excluded.sum_value,
                sample_count = excluded.sample_count,
                quality_count = excluded.quality_count",
            [$granularity, $window],
        );
    }
}