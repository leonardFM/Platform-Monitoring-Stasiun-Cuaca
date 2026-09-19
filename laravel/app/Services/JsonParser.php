<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Strict JSON payload parser mirroring the original serde-based schema contract:
 * required keys, no unknown fields, typed numeric sensors and RFC3339 timestamps.
 */
class JsonParser
{
    public const READING_KEYS = ['message_id', 'taken_at', 'sensors'];

    public const SENSOR_KEYS = [
        'temperature_c',
        'humidity_pct',
        'pressure_hpa',
        'windspeed_ms',
        'wind_direction_deg',
        'rain_counter',
    ];

    public static function parseReading(Request $request): array
    {
        $data = self::decode($request);

        return self::normalizeReading($data);
    }

    public static function parseBatch(Request $request): array
    {
        $data = self::decode($request);

        if (! is_array($data) || array_is_list($data)) {
            throw ApiException::badRequest('invalid JSON body: expected object with readings');
        }

        $allowed = ['readings'];
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown) {
            throw ApiException::badRequest('invalid JSON body: unknown field '.self::fields($unknown));
        }

        if (! isset($data['readings'])) {
            throw ApiException::badRequest('invalid JSON body: missing field readings');
        }

        if (! is_array($data['readings']) || ! array_is_list($data['readings'])) {
            throw ApiException::badRequest('invalid JSON body: readings must be an array');
        }

        return array_map(fn (mixed $r): array => self::normalizeReading($r), $data['readings']);
    }

    private static function decode(Request $request): mixed
    {
        $contentType = strtolower(trim(explode(';', $request->header('Content-Type', ''))[0]));

        if ($contentType !== 'application/json') {
            throw ApiException::badRequest('Content-Type must be application/json');
        }

        $raw = $request->getContent();
        if (trim($raw) === '') {
            throw ApiException::badRequest('invalid JSON body: empty request body');
        }

        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw ApiException::badRequest('invalid JSON body: '.json_last_error_msg());
        }

        return $data;
    }

    private static function normalizeReading(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw ApiException::badRequest('invalid JSON body: expected object');
        }

        $unknown = array_diff(array_keys($data), self::READING_KEYS);
        if ($unknown) {
            throw ApiException::badRequest('invalid JSON body: unknown field '.self::fields($unknown));
        }

        $required = array_diff(self::READING_KEYS, array_keys($data));
        if ($required) {
            throw ApiException::badRequest('invalid JSON body: missing field '.self::fields($required));
        }

        if (! is_string($data['message_id'])
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['message_id'])) {
            throw ApiException::badRequest('invalid JSON body: message_id must be a UUID');
        }

        $takenAt = self::parseTimestamp($data['taken_at']);

        $sensors = self::normalizeSensors($data['sensors']);

        return [
            'message_id' => strtolower($data['message_id']),
            'taken_at' => $takenAt,
            'sensors' => $sensors,
        ];
    }

    private static function normalizeSensors(mixed $s): array
    {
        if (! is_array($s) || array_is_list($s)) {
            throw ApiException::badRequest('invalid JSON body: sensors must be an object');
        }

        $unknown = array_diff(array_keys($s), self::SENSOR_KEYS);
        if ($unknown) {
            throw ApiException::badRequest('invalid JSON body: unknown field '.self::fields($unknown));
        }

        $required = array_diff(self::SENSOR_KEYS, array_keys($s));
        if ($required) {
            throw ApiException::badRequest('invalid JSON body: missing field '.self::fields($required));
        }

        $numericKeys = array_slice(self::SENSOR_KEYS, 0, 5);

        $out = [];
        foreach ($numericKeys as $key) {
            $value = $s[$key];
            if (! is_int($value) && ! is_float($value)) {
                throw ApiException::badRequest("invalid JSON body: {$key} must be a number");
            }
            if (is_float($value) && ! is_finite($value)) {
                throw ApiException::badRequest("invalid JSON body: {$key} must be a finite number");
            }
            $out[$key] = (float) $value;
        }

        $rain = $s['rain_counter'];
        if (! is_int($rain)) {
            throw ApiException::badRequest('invalid JSON body: rain_counter must be an integer');
        }
        $out['rain_counter'] = $rain;

        return $out;
    }

    private static function parseTimestamp(mixed $value): Carbon
    {
        if (! is_string($value)) {
            throw ApiException::badRequest('invalid JSON body: taken_at must be an RFC3339 timestamp');
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw ApiException::badRequest('invalid JSON body: taken_at must be an RFC3339 timestamp');
        }
    }

    private static function fields(array $fields): string
    {
        $quoted = array_map(fn (string $f): string => "'{$f}'", array_values($fields));

        return implode(', ', $quoted);
    }
}