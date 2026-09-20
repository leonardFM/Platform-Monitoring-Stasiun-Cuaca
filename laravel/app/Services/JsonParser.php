<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Parser payload F.1 (spek Luwes) — single, batch, dan heartbeat.
 * Firmware sudah terlanjur dibuat, format tidak boleh diubah; backend yang
 * menyesuaikan. Sensor yang sedang error TIDAK ikut dikirim oleh firmware,
 * sehingga readings[] boleh lebih pendek atau bahkan kosong.
 */
class JsonParser
{
    public const SENSOR_CODES = [
        'temp_air',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_dir',
        'rain_counter',
        'solar_rad',
    ];

    private const SINGLE_KEYS = ['device_id', 'fw', 'ts', 'seq', 'battery_v', 'rssi', 'readings'];
    private const BATCH_KEYS = ['device_id', 'fw', 'batch'];
    private const BATCH_ITEM_KEYS = ['ts', 'seq', 'battery_v', 'rssi', 'readings'];
    private const HEARTBEAT_KEYS = ['device_id', 'ts', 'fw', 'battery_v', 'rssi', 'uptime_s'];

    public static function parseSingle(Request $request): array
    {
        $data = self::decode($request);
        self::assertObject($data, 'payload');
        self::assertOnlyKeys($data, self::SINGLE_KEYS, 'payload');

        return [
            'device_id' => self::requiredString($data, 'device_id'),
            'fw' => self::optionalString($data['fw'] ?? null),
            'ts' => self::epochToCarbon($data['ts']),
            'seq' => self::nonNegativeInt($data['seq']),
            'battery_v' => self::optionalNumber($data['battery_v'] ?? null, 'battery_v'),
            'rssi' => self::optionalNumber($data['rssi'] ?? null, 'rssi'),
            'readings' => self::normalizeReadings($data['readings']),
        ];
    }

    public static function parseBatch(Request $request): array
    {
        $data = self::decode($request);
        self::assertObject($data, 'payload');
        self::assertOnlyKeys($data, self::BATCH_KEYS, 'payload');

        if (! isset($data['batch'])) {
            throw ApiException::badRequest('payload.batch is required');
        }

        $batch = $data['batch'];
        if (! is_array($batch) || ! array_is_list($batch)) {
            throw ApiException::badRequest('payload.batch must be an array');
        }

        $items = [];
        foreach ($batch as $i => $item) {
            self::assertObject($item, "batch[{$i}]");
            self::assertOnlyKeys($item, self::BATCH_ITEM_KEYS, "batch[{$i}]");

            $items[] = [
                'ts' => self::epochToCarbon($item['ts']),
                'seq' => self::nonNegativeInt($item['seq']),
                'battery_v' => self::optionalNumber($item['battery_v'] ?? null, "batch[{$i}].battery_v"),
                'rssi' => self::optionalNumber($item['rssi'] ?? null, "batch[{$i}].rssi"),
                'readings' => self::normalizeReadings($item['readings']),
            ];
        }

        return [
            'device_id' => self::requiredString($data, 'device_id'),
            'fw' => self::optionalString($data['fw'] ?? null),
            'items' => $items,
        ];
    }

    public static function parseHeartbeat(Request $request): array
    {
        $data = self::decode($request);
        self::assertObject($data, 'heartbeat');
        self::assertOnlyKeys($data, self::HEARTBEAT_KEYS, 'heartbeat');

        return [
            'device_id' => self::requiredString($data, 'device_id'),
            'ts' => self::epochToCarbon($data['ts']),
            'fw' => self::optionalString($data['fw'] ?? null),
            'battery_v' => self::optionalNumber($data['battery_v'] ?? null, 'battery_v'),
            'rssi' => self::optionalNumber($data['rssi'] ?? null, 'rssi'),
            'uptime_s' => self::optionalNumber($data['uptime_s'] ?? null, 'uptime_s'),
        ];
    }

    private static function normalizeReadings(mixed $readings): array
    {
        if (! is_array($readings) || ! array_is_list($readings)) {
            throw ApiException::badRequest('payload.readings must be an array of {s, v} objects');
        }

        $out = [];
        foreach ($readings as $i => $entry) {
            $path = "readings[{$i}]";
            self::assertObject($entry, $path);
            self::assertOnlyKeys($entry, ['s', 'v'], $path);

            $code = $entry['s'] ?? null;
            if (! is_string($code) || ! in_array($code, self::SENSOR_CODES, true)) {
                throw ApiException::badRequest("{$path}.s: unknown sensor code '".var_export($code, true)."'");
            }
            if (array_key_exists($code, $out)) {
                throw ApiException::badRequest("{$path}: duplicate sensor code '{$code}'");
            }

            $value = $entry['v'] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                throw ApiException::badRequest("{$path}.v: value for {$code} must be a number");
            }
            if (is_float($value) && ! is_finite($value)) {
                throw ApiException::badRequest("{$path}.v: value for {$code} must be a finite number");
            }

            $out[$code] = (float) $value;
        }

        return $out;
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

    private static function assertObject(mixed $data, string $path): void
    {
        if (! is_array($data) || array_is_list($data)) {
            throw ApiException::badRequest("{$path} must be a JSON object");
        }
    }

    private static function assertOnlyKeys(array $data, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown) {
            throw ApiException::badRequest("{$path}: unknown field ".self::fields($unknown));
        }

        $required = array_diff($allowed, array_keys($data));
        if ($required) {
            // 'readings' always required di single/batch-item; optional keys difilter di sini.
            $hard = array_values(array_filter($required, fn (string $k): bool => $k === 'readings'));
            if ($hard) {
                throw ApiException::badRequest("{$path}: missing required field ".self::fields($hard));
            }
        }
    }

    private static function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw ApiException::badRequest("{$field} must be a non-empty string");
        }

        return $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ApiException::badRequest('fw must be a string');
        }

        return $value;
    }

    private static function nonNegativeInt(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw ApiException::badRequest('seq must be a non-negative integer');
        }

        return $value;
    }

    private static function optionalNumber(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_float($value)) {
            throw ApiException::badRequest("{$field} must be a number");
        }
        if (is_float($value) && ! is_finite($value)) {
            throw ApiException::badRequest("{$field} must be a finite number");
        }

        return (float) $value;
    }

    private static function epochToCarbon(mixed $value): Carbon
    {
        if (! is_int($value)) {
            throw ApiException::badRequest('ts must be a Unix epoch in seconds (integer)');
        }

        return Carbon::createFromTimestampUTC($value);
    }

    private static function fields(array $fields): string
    {
        $quoted = array_map(fn (string $f): string => "'{$f}'", array_values($fields));

        return implode(', ', $quoted);
    }
}