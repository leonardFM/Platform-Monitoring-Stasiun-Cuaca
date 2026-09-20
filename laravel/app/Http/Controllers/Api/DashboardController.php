<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

final class DashboardController
{
    private const SERIES_CODES = [
        'temp_air' => 'temperature_avg',
        'humidity' => 'humidity_avg',
        'pressure' => 'pressure_avg',
        'wind_speed' => 'windspeed_avg',
        'wind_dir' => 'wind_direction_avg',
    ];

    private const LATEST_CODES = [
        'temp_air' => 'temperature_c',
        'humidity' => 'humidity_pct',
        'pressure' => 'pressure_hpa',
        'wind_speed' => 'windspeed_ms',
        'wind_dir' => 'wind_direction_deg',
    ];

    #[OA\Get(
        path: '/api/v1/dashboard',
        tags: ['dashboard'],
        responses: [
            new OA\Response(response: '200', description: 'Dashboard payload', content: new OA\JsonContent(ref: '#/components/schemas/DashboardResponse')),
            new OA\Response(response: '500', description: 'Query failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        try {
            return response()->json([
                'devices' => $this->devices(),
                'series' => $this->series(),
                'recent' => $this->recent(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            throw ApiException::internal('internal server error');
        }
    }

    private function devices(): array
    {
        $devices = DB::select(
            'SELECT d.id, d.name, d.status,
                    l.id AS location_id, l.name AS location_name,
                    l.latitude, l.longitude, l.altitude
             FROM devices d
             JOIN locations l ON l.id = d.location_id
             WHERE d.deleted_at IS NULL
             ORDER BY d.name',
        );

        $latestByDevice = [];
        $latestRows = DB::select(
            'SELECT DISTINCT ON (d.id, st.code)
                    d.id AS device_id, st.code,
                    sr.device_time, sr.raw_value, sr.corrected_value, sr.quality_flag
             FROM devices d
             JOIN sensor_installations si ON si.device_id = d.id AND si.removed_at IS NULL
             JOIN sensors s ON s.id = si.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             LEFT JOIN sensor_readings sr ON sr.sensor_id = s.id
             WHERE d.deleted_at IS NULL
             ORDER BY d.id, st.code, sr.device_time DESC NULLS LAST',
        );
        foreach ($latestRows as $row) {
            $latestByDevice[(string) $row->device_id][$row->code] = $row;
        }

        $rainByDevice = [];
        $rainRows = DB::select(
            'SELECT si.device_id, COALESCE(SUM(sr.corrected_value), 0)::DOUBLE PRECISION AS rain_mm
             FROM sensor_installations si
             JOIN sensors s ON s.id = si.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id AND st.code = \'rain_counter\'
             LEFT JOIN sensor_readings sr ON sr.sensor_id = s.id
                AND sr.device_time >= date_trunc(\'day\', now())
             WHERE si.removed_at IS NULL
             GROUP BY si.device_id',
        );
        foreach ($rainRows as $row) {
            $rainByDevice[(string) $row->device_id] = (float) $row->rain_mm;
        }

        $devicesJson = [];
        foreach ($devices as $d) {
            $codeRows = array_filter(
                $latestByDevice[(string) $d->id] ?? [],
                fn ($row) => $row->device_time !== null,
            );

            $latest = null;
            $latestTs = null;
            foreach ($codeRows as $row) {
                $ts = Carbon::parse($row->device_time);
                if ($latestTs === null || $ts->greaterThan($latestTs)) {
                    $latestTs = $ts;
                }
            }

            if ($latestTs !== null) {
                $latest = [
                    'taken_at' => $latestTs->toIso8601ZuluString(),
                    'quality_score' => $this->combinedScore($codeRows),
                ];

                foreach (self::LATEST_CODES as $code => $key) {
                    if (! isset($codeRows[$code])) {
                        continue;
                    }
                    $row = $codeRows[$code];
                    $latest[$key] = self::num($row->corrected_value ?? $row->raw_value);
                }

                if (isset($codeRows['rain_counter'])) {
                    $latest['rain_counter'] = self::int($codeRows['rain_counter']->raw_value);
                    $latest['rain_delta_mm'] = self::num($codeRows['rain_counter']->corrected_value);
                }
            }

            $devicesJson[] = [
                'id' => (string) $d->id,
                'name' => $d->name,
                'location' => [
                    'name' => $d->location_name,
                    'latitude' => (float) $d->latitude,
                    'longitude' => (float) $d->longitude,
                    'altitude' => $d->altitude !== null ? (float) $d->altitude : null,
                ],
                'rain_today_mm' => $rainByDevice[(string) $d->id] ?? 0.0,
                'latest' => $latest,
            ];
        }

        return $devicesJson;
    }

    private function series(): array
    {
        $rows = DB::select(
            'SELECT ra.bucket_start, d.id AS device_id, d.name AS device_name, st.code,
                    ra.avg_value
             FROM reading_aggregates ra
             JOIN sensors s ON s.id = ra.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id AND st.code <> \'rain_counter\'
             LEFT JOIN LATERAL (
                SELECT si.device_id
                FROM sensor_installations si
                WHERE si.sensor_id = ra.sensor_id
                  AND si.installed_at <= ra.bucket_start
                  AND (si.removed_at IS NULL OR si.removed_at > ra.bucket_start)
                ORDER BY si.installed_at DESC
                LIMIT 1
             ) si ON TRUE
             JOIN devices d ON d.id = si.device_id AND d.deleted_at IS NULL
             WHERE ra."interval" = \'1h\'
               AND ra.bucket_start >= now() - interval \'24 hours\'
             ORDER BY ra.bucket_start ASC, d.name ASC',
        );

        $rainByKey = [];
        $rainRows = DB::select(
            'SELECT si.device_id, date_trunc(\'hour\', sr.device_time) AS bucket_start,
                    SUM(sr.corrected_value)::DOUBLE PRECISION AS rain_mm
             FROM sensor_installations si
             JOIN sensors s ON s.id = si.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id AND st.code = \'rain_counter\'
             JOIN sensor_readings sr ON sr.sensor_id = s.id
             WHERE si.removed_at IS NULL
               AND sr.device_time >= now() - interval \'24 hours\'
             GROUP BY si.device_id, date_trunc(\'hour\', sr.device_time)',
        );
        foreach ($rainRows as $row) {
            $rainByKey[$this->seriesKey($row->bucket_start, $row->device_id)] = (float) $row->rain_mm;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $key = $this->seriesKey($row->bucket_start, $row->device_id);
            $grouped[$key] ??= [
                'period_start' => Carbon::parse($row->bucket_start)->toIso8601ZuluString(),
                'device_name' => $row->device_name,
            ];
            $code = $row->code;
            $target = self::SERIES_CODES[$code] ?? null;
            if ($target !== null) {
                $grouped[$key][$target] = self::num($row->avg_value);
            }
        }

        ksort($grouped);

        $seriesJson = [];
        foreach ($grouped as $key => $item) {
            $seriesJson[] = $item + ['rain_total_mm' => $rainByKey[$key] ?? 0.0];
        }

        return $seriesJson;
    }

    private function recent(): array
    {
        $times = DB::select(
            'SELECT device_id, device_time
             FROM sensor_readings
             GROUP BY device_id, device_time
             ORDER BY device_time DESC
             LIMIT 30',
        );

        if ($times === []) {
            return [];
        }

        $tuples = [];
        $bindings = [];
        foreach ($times as $t) {
            $tuples[] = '(?, ?)';
            $bindings[] = $t->device_id;
            $bindings[] = $t->device_time;
        }

        $rows = DB::select(
            'SELECT sr.device_id, sr.device_time, d.name AS device_name, st.code,
                    sr.raw_value, sr.corrected_value, sr.quality_flag
             FROM sensor_readings sr
             JOIN devices d ON d.id = sr.device_id AND d.deleted_at IS NULL
             JOIN sensors s ON s.id = sr.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             WHERE (sr.device_id, sr.device_time) IN (' . implode(', ', $tuples) . ')
             ORDER BY sr.device_time DESC, st.code ASC',
            $bindings,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->device_id . '|' . $row->device_time][] = $row;
        }

        $recentJson = [];
        foreach ($grouped as $group) {
            $first = $group[0];
            $item = [
                'taken_at' => Carbon::parse($first->device_time)->toIso8601ZuluString(),
                'device_name' => $first->device_name,
                'quality_score' => $this->combinedScore($group),
                'quality_flags' => $this->flagsOf($group),
            ];

            foreach ($group as $row) {
                if ($row->code === 'rain_counter') {
                    $item['rain_counter'] = self::int($row->raw_value);
                    $item['rain_delta_mm'] = self::num($row->corrected_value);
                    continue;
                }
                $key = self::LATEST_CODES[$row->code] ?? null;
                if ($key !== null) {
                    $item[$key] = self::num($row->corrected_value ?? $row->raw_value);
                }
            }

            $recentJson[] = $item;
        }

        return $recentJson;
    }

    private function seriesKey(mixed $bucketStart, mixed $deviceId): string
    {
        return Carbon::parse($bucketStart)->toIso8601ZuluString() . '|' . (string) $deviceId;
    }

    private function combinedScore(array $rows): int
    {
        $score = 100;
        foreach ($rows as $row) {
            $score = min($score, self::qualityScore($row->quality_flag));
        }

        return $score;
    }

    private function flagsOf(array $rows): array
    {
        $flags = [];
        foreach ($rows as $row) {
            $flag = $row->quality_flag;
            if ($flag === 'GOOD' || in_array($flag, $flags, true)) {
                continue;
            }
            $flags[] = $flag;
        }

        return $flags;
    }

    private static function qualityScore(string $flag): int
    {
        return match ($flag) {
            'GOOD' => 100,
            'CLOCK_DRIFT' => 70,
            'LATE' => 60,
            'RAIN_INITIAL' => 50,
            'OUT_OF_RANGE' => 40,
            'RAIN_RESET' => 30,
            'SENSOR_ERROR' => 20,
            default => 10,
        };
    }

    private static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}