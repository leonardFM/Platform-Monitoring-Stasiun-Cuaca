<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

final class DashboardController
{
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
            $devices = DB::select(
                'SELECT DISTINCT ON (d.id)
                       d.id, d.name, d.location,
                       r.taken_at, r.temperature_c, r.humidity_pct, r.pressure_hpa,
                       r.windspeed_ms, r.wind_direction_deg, r.rain_counter,
                       r.rain_delta_mm, r.quality_score
                FROM devices d
                LEFT JOIN telemetry_readings r ON r.device_id = d.id
                ORDER BY d.id, r.taken_at DESC NULLS LAST',
            );

            $rain = DB::select(
                'SELECT d.id, COALESCE(SUM(r.rain_delta_mm), 0)::DOUBLE PRECISION AS rain_mm
                 FROM devices d
                 LEFT JOIN telemetry_readings r
                        ON r.device_id = d.id AND r.taken_at >= date_trunc(\'day\', now())
                 GROUP BY d.id',
            );

            $series = DB::select(
                'SELECT a.period_start, d.name AS device_name,
                        a.temperature_avg, a.humidity_avg, a.pressure_avg,
                        a.windspeed_avg, a.wind_direction_avg, a.rain_total_mm
                 FROM station_aggregates a
                 JOIN devices d ON d.id = a.device_id
                 WHERE a.granularity = \'hour\'
                   AND a.period_start >= now() - interval \'24 hours\'
                 ORDER BY a.period_start ASC, d.name ASC',
            );

            $recent = DB::select(
                'SELECT r.taken_at, d.name AS device_name,
                        r.temperature_c, r.humidity_pct, r.pressure_hpa,
                        r.windspeed_ms, r.wind_direction_deg, r.rain_counter,
                        r.rain_delta_mm, r.quality_score, r.quality_flags
                 FROM telemetry_readings r
                 JOIN devices d ON d.id = r.device_id
                 ORDER BY r.taken_at DESC
                 LIMIT 30',
            );
        } catch (\Throwable $e) {
            report($e);

            throw ApiException::internal('internal server error');
        }

        $rainByDevice = [];
        foreach ($rain as $row) {
            $rainByDevice[(string) $row->id] = (float) $row->rain_mm;
        }

        $devicesJson = [];
        foreach ($devices as $d) {
            $devicesJson[] = [
                'id' => (string) $d->id,
                'name' => $d->name,
                'location' => self::jsonValue($d->location),
                'rain_today_mm' => $rainByDevice[(string) $d->id] ?? 0.0,
                'latest' => $d->taken_at === null ? null : [
                    'taken_at' => self::timestamp($d->taken_at),
                    'temperature_c' => self::num($d->temperature_c),
                    'humidity_pct' => self::num($d->humidity_pct),
                    'pressure_hpa' => self::num($d->pressure_hpa),
                    'windspeed_ms' => self::num($d->windspeed_ms),
                    'wind_direction_deg' => self::num($d->wind_direction_deg),
                    'rain_counter' => self::int($d->rain_counter),
                    'rain_delta_mm' => self::num($d->rain_delta_mm),
                    'quality_score' => self::int($d->quality_score),
                ],
            ];
        }

        $seriesJson = [];
        foreach ($series as $s) {
            $seriesJson[] = [
                'period_start' => self::timestamp($s->period_start),
                'device_name' => $s->device_name,
                'temperature_avg' => self::num($s->temperature_avg),
                'humidity_avg' => self::num($s->humidity_avg),
                'pressure_avg' => self::num($s->pressure_avg),
                'windspeed_avg' => self::num($s->windspeed_avg),
                'wind_direction_avg' => self::num($s->wind_direction_avg),
                'rain_total_mm' => (float) $s->rain_total_mm,
            ];
        }

        $recentJson = [];
        $recentKeys = [
            'temperature_c',
            'humidity_pct',
            'pressure_hpa',
            'windspeed_ms',
            'wind_direction_deg',
        ];
        foreach ($recent as $r) {
            $item = [
                'taken_at' => self::timestamp($r->taken_at),
                'device_name' => $r->device_name,
                'rain_counter' => self::int($r->rain_counter),
                'rain_delta_mm' => self::num($r->rain_delta_mm),
                'quality_score' => self::int($r->quality_score),
                'quality_flags' => self::jsonValue($r->quality_flags),
            ];
            foreach ($recentKeys as $key) {
                $item[$key] = self::num($r->{$key});
            }
            $recentJson[] = $item;
        }

        return response()->json([
            'devices' => $devicesJson,
            'series' => $seriesJson,
            'recent' => $recentJson,
        ]);
    }

    private static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function timestamp(mixed $value): string
    {
        return Carbon::parse($value)->toIso8601ZuluString();
    }

    private static function jsonValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return $decoded === null && strcasecmp(trim($value), 'null') !== 0 ? $value : $decoded;
        }

        return $value;
    }
}