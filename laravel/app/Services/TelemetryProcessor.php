<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RabbitMQ consumer pipeline. Every accepted telemetry message is processed
 * here: device lookup, strict range/quality flagging, calibration, per-device
 * serialized rain-delta computation and idempotent persistence with hourly/
 * daily aggregate rollups. Mirrors the original worker implementation.
 */
class TelemetryProcessor
{
    public function process(string $deviceId, string $messageId, string $takenAt, array $sensors): void
    {
        $takenAt = Carbon::parse($takenAt);

        $device = DB::selectOne(
            'SELECT is_active, calibration FROM devices WHERE id = ?',
            [$deviceId],
        );

        if (! $device || ! self::truthy($device->is_active)) {
            Log::info('device not found or inactive, skipping', [
                'device_id' => $deviceId,
                'message_id' => $messageId,
            ]);

            return;
        }

        $qualityFlags = self::rangeErrors($sensors);
        $qualityScore = self::qualityScoreFor($qualityFlags);

        $calibration = self::calibrationMap($device->calibration);
        $calibrated = self::applyCalibration($sensors, $calibration);
        if (count($calibration) > 0) {
            $qualityFlags[] = 'calibrated';
        }

        DB::beginTransaction();

        try {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))',
                [$deviceId],
            );

            $previous = DB::selectOne(
                'SELECT rain_counter, taken_at FROM telemetry_readings
                 WHERE device_id = ? ORDER BY taken_at DESC LIMIT 1',
                [$deviceId],
            );

            [$rainDelta, $rainFlags] = self::rainDelta($previous, $takenAt, $sensors['rain_counter']);

            if (in_array('rain_initial', $rainFlags, true)) {
                $qualityScore = max(0, $qualityScore - 5);
            }
            if (in_array('rain_reset', $rainFlags, true)) {
                $qualityScore = max(0, $qualityScore - 5);
            }
            $qualityFlags = array_merge($qualityFlags, $rainFlags);

            $inserted = self::insertReading(
                $deviceId,
                $messageId,
                $takenAt,
                $calibrated,
                $rainDelta,
                $qualityFlags,
                $qualityScore,
            );

            if (! $inserted) {
                DB::rollBack();
                Log::info('duplicate message_id, skipping', [
                    'device_id' => $deviceId,
                    'message_id' => $messageId,
                ]);

                return;
            }

            $this->upsertAggregates($deviceId, $takenAt, $calibrated, $rainDelta);

            DB::commit();

            Log::info('reading persisted', [
                'device_id' => $deviceId,
                'message_id' => $messageId,
                'quality_score' => $qualityScore,
            ]);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }
    }

    private function insertReading(
        string $deviceId,
        string $messageId,
        Carbon $takenAt,
        array $calibrated,
        float $rainDelta,
        array $qualityFlags,
        int $qualityScore,
    ): bool {
        $affected = DB::update(
            "INSERT INTO telemetry_readings (
                device_id, message_id, taken_at,
                temperature_c, humidity_pct, pressure_hpa,
                windspeed_ms, wind_direction_deg, rain_counter,
                rain_delta_mm, quality_flags, quality_score
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (device_id, message_id) DO NOTHING",
            [
                $deviceId,
                $messageId,
                $takenAt->toIso8601String(),
                $calibrated['temperature_c'],
                $calibrated['humidity_pct'],
                $calibrated['pressure_hpa'],
                $calibrated['windspeed_ms'],
                $calibrated['wind_direction_deg'],
                $calibrated['rain_counter'],
                $rainDelta,
                json_encode($qualityFlags),
                $qualityScore,
            ],
        );

        return $affected === 1;
    }

    private function upsertAggregates(string $deviceId, Carbon $takenAt, array $c, float $rainDelta): void
    {
        foreach (['hour', 'day'] as $granularity) {
            DB::update(
                "INSERT INTO station_aggregates (
                    device_id, granularity, period_start, count,
                    temperature_avg, temperature_min, temperature_max,
                    humidity_avg, humidity_min, humidity_max,
                    pressure_avg, pressure_min, pressure_max,
                    windspeed_avg, windspeed_min, windspeed_max,
                    wind_direction_avg, rain_total_mm
                )
                VALUES (?, ?, date_trunc(?::text, ?::timestamptz), 1,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (device_id, granularity, period_start) DO UPDATE SET
                    count = station_aggregates.count + 1,
                    temperature_avg = (station_aggregates.temperature_avg * station_aggregates.count + excluded.temperature_avg) / (station_aggregates.count + 1),
                    temperature_min = LEAST(station_aggregates.temperature_min, excluded.temperature_min),
                    temperature_max = GREATEST(station_aggregates.temperature_max, excluded.temperature_max),
                    humidity_avg = (station_aggregates.humidity_avg * station_aggregates.count + excluded.humidity_avg) / (station_aggregates.count + 1),
                    humidity_min = LEAST(station_aggregates.humidity_min, excluded.humidity_min),
                    humidity_max = GREATEST(station_aggregates.humidity_max, excluded.humidity_max),
                    pressure_avg = (station_aggregates.pressure_avg * station_aggregates.count + excluded.pressure_avg) / (station_aggregates.count + 1),
                    pressure_min = LEAST(station_aggregates.pressure_min, excluded.pressure_min),
                    pressure_max = GREATEST(station_aggregates.pressure_max, excluded.pressure_max),
                    windspeed_avg = (station_aggregates.windspeed_avg * station_aggregates.count + excluded.windspeed_avg) / (station_aggregates.count + 1),
                    windspeed_min = LEAST(station_aggregates.windspeed_min, excluded.windspeed_min),
                    windspeed_max = GREATEST(station_aggregates.windspeed_max, excluded.windspeed_max),
                    wind_direction_avg = (station_aggregates.wind_direction_avg * station_aggregates.count + excluded.wind_direction_avg) / (station_aggregates.count + 1),
                    rain_total_mm = station_aggregates.rain_total_mm + excluded.rain_total_mm",
                [
                    $deviceId,
                    $granularity,
                    $granularity,
                    $takenAt->toIso8601String(),
                    $temperature = $c['temperature_c'],
                    $temperature,
                    $temperature,
                    $humidity = $c['humidity_pct'],
                    $humidity,
                    $humidity,
                    $pressure = $c['pressure_hpa'],
                    $pressure,
                    $pressure,
                    $windspeed = $c['windspeed_ms'],
                    $windspeed,
                    $windspeed,
                    $c['wind_direction_deg'],
                    $rainDelta,
                ],
            );
        }
    }

    /**
     * Strict physical bounds per sensor (worker-side quality flagging).
     */
    public static function sensorBounds(): array
    {
        return [
            'temperature_c' => [-60.0, 60.0],
            'humidity_pct' => [0.0, 100.0],
            'pressure_hpa' => [800.0, 1100.0],
            'windspeed_ms' => [0.0, 100.0],
            'wind_direction_deg' => [0.0, 360.0],
        ];
    }

    public static function rangeErrors(array $sensors): array
    {
        $flags = [];

        foreach (self::sensorBounds() as $name => [$lo, $hi]) {
            $value = $sensors[$name];
            if (! is_finite($value) || $value < $lo || $value > $hi) {
                $flags[] = "out_of_range:{$name}";
            }
        }

        if ($sensors['rain_counter'] < 0) {
            $flags[] = 'out_of_range:rain_counter';
        }

        return $flags;
    }

    public static function qualityScoreFor(array $flags): int
    {
        $outOfRange = 0;
        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'out_of_range')) {
                $outOfRange++;
            }
        }

        $total = 6;
        $valid = $total - $outOfRange;

        return max(0, (int) (100 * $valid / $total));
    }

    public static function calibrationMap(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * value = raw * gain + offset (per device config). rain_counter stays raw.
     */
    public static function applyCalibration(array $sensors, array $calibration): array
    {
        $out = $sensors;

        foreach (['temperature_c', 'humidity_pct', 'pressure_hpa', 'windspeed_ms', 'wind_direction_deg'] as $name) {
            $coeffs = $calibration[$name] ?? null;
            $gain = is_array($coeffs) ? ($coeffs['gain'] ?? 1.0) : 1.0;
            $offset = is_array($coeffs) ? ($coeffs['offset'] ?? 0.0) : 0.0;
            $out[$name] = $sensors[$name] * $gain + $offset;
        }

        return $out;
    }

    /**
     * Computes a rain delta in counter units (interpreted as millimetres),
     * handling first readings, counter resets and out-of-order arrivals.
     */
    public static function rainDelta(?object $previous, Carbon $takenAt, int $current): array
    {
        $flags = [];

        if ($previous === null) {
            $flags[] = 'rain_initial';

            return [0.0, $flags];
        }

        $prevTakenAt = $previous->taken_at ? Carbon::parse($previous->taken_at) : null;
        $prevCounter = $previous->rain_counter !== null ? (int) $previous->rain_counter : null;

        if ($prevTakenAt === null || $prevCounter === null) {
            $flags[] = 'rain_initial';

            return [0.0, $flags];
        }

        if ($takenAt->lte($prevTakenAt)) {
            $flags[] = 'rain_out_of_order';
        }

        if ($current < $prevCounter) {
            $flags[] = 'rain_reset';

            return [0.0, $flags];
        }

        return [(float) ($current - $prevCounter), $flags];
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}