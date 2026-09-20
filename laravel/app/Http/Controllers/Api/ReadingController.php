<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'readings', description: 'Timeseries readings & summaries')]
final class ReadingController
{
    private const INTERVALS = ['raw', '1m', '1h', '1d'];

    #[OA\Get(
        path: '/api/v1/readings',
        tags: ['readings'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'interval', in: 'query', description: 'Bucket granularity. raw = base readings (max 24h span), 1m = calculated on-the-fly (max 14 days), 1h / 1d = pre-aggregated', schema: new OA\Schema(type: 'string', enum: ['raw', '1m', '1h', '1d'], default: '1h')),
            new OA\Parameter(name: 'from', in: 'query', description: 'Bucket/reading start (ISO 8601 or unix seconds). Defaults to 24h before to', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', description: 'Bucket/reading end (exclusive). Defaults to now', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'sensor_id', in: 'query', description: 'Filter by sensor UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'device_id', in: 'query', description: 'Filter by device UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sensor_type_id', in: 'query', description: 'Filter by sensor type UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 100, maximum: 1000)),
            new OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Reading series', content: new OA\JsonContent(ref: '#/components/schemas/ReadingSeriesResponse')),
            new OA\Response(response: '400', description: 'Invalid interval or range', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->series($request, null);
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}/readings',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'interval', in: 'query', description: 'Bucket granularity', schema: new OA\Schema(type: 'string', enum: ['raw', '1m', '1h', '1d'], default: '1h')),
            new OA\Parameter(name: 'from', in: 'query', description: 'Bucket/reading start', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', description: 'Bucket/reading end (exclusive)', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'sensor_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sensor_type_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 100, maximum: 1000)),
            new OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Device readings series', content: new OA\JsonContent(ref: '#/components/schemas/ReadingSeriesResponse')),
            new OA\Response(response: '400', description: 'Invalid interval or range', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'Device not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function device(Request $request, string $device): JsonResponse
    {
        $deviceModel = Device::find($device);

        if (! $deviceModel) {
            throw ApiException::notFound('Device not found');
        }

        return $this->series($request, (string) $deviceModel->id);
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}/readings/latest',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Latest reading per sensor for a device', content: new OA\JsonContent(ref: '#/components/schemas/DeviceLatestReadingsResponse')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'Device not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function latest(Request $request, string $device): JsonResponse
    {
        $deviceModel = Device::find($device);

        if (! $deviceModel) {
            throw ApiException::notFound('Device not found');
        }

        $rows = DB::select(
            'SELECT DISTINCT ON (st.code)
                    sr.device_time, sr.raw_value, sr.corrected_value, sr.quality_flag,
                    s.id AS sensor_id, s.serial_number,
                    st.code AS sensor_code, st.name AS sensor_name, st.unit
             FROM sensor_readings sr
             JOIN sensors s ON s.id = sr.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             JOIN sensor_installations si ON si.sensor_id = s.id
                 AND si.device_id = ?
                 AND si.installed_at <= sr.device_time
                 AND (si.removed_at IS NULL OR si.removed_at > sr.device_time)
             WHERE sr.device_id = ?
             ORDER BY st.code ASC, sr.device_time DESC',
            [$deviceModel->id, $deviceModel->id],
        );

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'sensor_id' => (string) $row->sensor_id,
                'serial_number' => $row->serial_number,
                'sensor_code' => $row->sensor_code,
                'sensor_name' => $row->sensor_name,
                'unit' => $row->unit,
                'device_time' => Carbon::parse($row->device_time)->toIso8601ZuluString(),
                'raw_value' => self::num($row->raw_value),
                'corrected_value' => self::num($row->corrected_value),
                'quality_flag' => $row->quality_flag,
            ];
        }

        return response()->json([
            'device_id' => (string) $deviceModel->id,
            'device_name' => $deviceModel->name,
            'data' => $data,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/readings/summary',
        tags: ['readings'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'interval', in: 'query', description: 'Aggregate granularity to summarize', schema: new OA\Schema(type: 'string', enum: ['1m', '1h', '1d'], default: '1d')),
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'sensor_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'device_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sensor_type_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Reading summary', content: new OA\JsonContent(ref: '#/components/schemas/ReadingSummary')),
            new OA\Response(response: '400', description: 'Invalid interval or range', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $interval = (string) $request->string('interval', '1d');
        if (! in_array($interval, self::INTERVALS, true) || $interval === 'raw') {
            throw ApiException::badRequest('summary interval must be one of: 1m, 1h, 1d');
        }

        [$from, $to] = $this->range($request);

        $filters = $this->filters($request, null);
        $where = ["ra.\"interval\" = ?", 'ra.bucket_start >= ?', 'ra.bucket_start < ?'];
        $bindings = [$interval, $from->toIso8601String(), $to->toIso8601String()];
        $this->appendFilters($where, $bindings, $filters, 'ra', 'st');

        $rows = DB::select(
            'SELECT s.id AS sensor_id, st.code AS sensor_code, st.name AS sensor_name, st.unit,
                    d.id AS device_id, d.name AS device_name,
                    COUNT(*)::INTEGER AS bucket_count,
                    MIN(ra.min_value) AS min_value,
                    MAX(ra.max_value) AS max_value,
                    COALESCE(SUM(ra.sum_value)::FLOAT / NULLIF(SUM(ra.sample_count), 0)::FLOAT, NULL) AS avg_value,
                    SUM(ra.sum_value) AS sum_value,
                    SUM(ra.sample_count)::INTEGER AS sample_count,
                    SUM(ra.quality_count)::INTEGER AS quality_count
             FROM reading_aggregates ra
             JOIN sensors s ON s.id = ra.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             LEFT JOIN LATERAL (
                SELECT si.device_id
                FROM sensor_installations si
                WHERE si.sensor_id = ra.sensor_id
                  AND si.installed_at <= ra.bucket_start
                  AND (si.removed_at IS NULL OR si.removed_at > ra.bucket_start)
                ORDER BY si.installed_at DESC
                LIMIT 1
             ) si ON TRUE
             LEFT JOIN devices d ON d.id = si.device_id AND d.deleted_at IS NULL
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY s.id, st.code, st.name, st.unit, d.id, d.name
             ORDER BY st.code ASC, d.name ASC',
            $bindings,
        );

        $totalBuckets = 0;
        $totalSamples = 0;
        $totalQuality = 0;
        $min = null;
        $max = null;
        $sum = 0.0;

        $bySensor = [];
        foreach ($rows as $row) {
            $bucketCount = (int) $row->bucket_count;
            $sampleCount = (int) $row->sample_count;
            $qualityCount = (int) $row->quality_count;
            $sumValue = (float) $row->sum_value;

            $totalBuckets += $bucketCount;
            $totalSamples += $sampleCount;
            $totalQuality += $qualityCount;
            $sum += $sumValue;
            $min = self::lowest($min, $row->min_value);
            $max = self::highest($max, $row->max_value);

            $bySensor[] = [
                'sensor_id' => (string) $row->sensor_id,
                'sensor_code' => $row->sensor_code,
                'sensor_name' => $row->sensor_name,
                'unit' => $row->unit,
                'device_id' => $row->device_id ? (string) $row->device_id : null,
                'device_name' => $row->device_name,
                'bucket_count' => $bucketCount,
                'min_value' => self::num($row->min_value),
                'max_value' => self::num($row->max_value),
                'avg_value' => self::num($row->avg_value),
                'sum_value' => self::num($row->sum_value),
                'sample_count' => $sampleCount,
                'quality_count' => $qualityCount,
            ];
        }

        return response()->json([
            'interval' => $interval,
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'filters' => $filters,
            'summary' => [
                'bucket_count' => $totalBuckets,
                'sensor_count' => count($bySensor),
                'min_value' => $min,
                'max_value' => $max,
                'avg_value' => $totalSamples > 0 ? $sum / $totalSamples : null,
                'sum_value' => $sum,
                'sample_count' => $totalSamples,
                'quality_count' => $totalQuality,
                'quality_ratio' => $totalSamples > 0 ? round($totalQuality / $totalSamples, 6) : null,
            ],
            'by_sensor' => $bySensor,
        ]);
    }

    private function series(Request $request, ?string $fixedDeviceId): JsonResponse
    {
        $interval = (string) $request->string('interval', '1h');
        if (! in_array($interval, self::INTERVALS, true)) {
            throw ApiException::badRequest('interval must be one of: raw, 1m, 1h, 1d');
        }

        [$from, $to] = $this->range($request);

        $maxSpanHours = match ($interval) {
            'raw' => (int) config('telemetry.ts_raw_max_span_hours', 24),
            '1m' => (int) config('telemetry.ts_1m_max_span_hours', 14 * 24),
            default => null,
        };
        if ($maxSpanHours !== null && $from->diffInHours($to) > $maxSpanHours) {
            throw ApiException::badRequest(
                sprintf('interval %s supports at most %d hours of data', $interval, $maxSpanHours),
            );
        }

        $filters = $this->filters($request, $fixedDeviceId);

        $limit = (int) $request->integer('limit', 100);
        $limit = max(1, min($limit, (int) config('telemetry.ts_max_limit', 1000)));
        $offset = max($request->integer('offset', 0), 0);

        $result = match ($interval) {
            'raw' => $this->raw($filters, $from, $to, $limit, $offset),
            '1m' => $this->minuteAggregates($filters, $from, $to, $limit, $offset),
            default => $this->storedAggregates($interval, $filters, $from, $to, $limit, $offset),
        };

        return response()->json([
            'data' => $result['data'],
            'meta' => [
                'interval' => $interval,
                'from' => $from->toIso8601ZuluString(),
                'to' => $to->toIso8601ZuluString(),
                'limit' => $limit,
                'offset' => $offset,
                'total' => $result['total'],
            ],
        ]);
    }

    private function range(Request $request): array
    {
        $to = $this->parseTime($request->input('to')) ?? Carbon::now();
        $from = $this->parseTime($request->input('from')) ?? (clone $to)->subDay();

        if ($from->greaterThanOrEqualTo($to)) {
            throw ApiException::badRequest('from must be earlier than to');
        }

        return [$from, $to];
    }

    private function filters(Request $request, ?string $fixedDeviceId): array
    {
        return [
            'sensor_id' => self::queryValue($request, 'sensor_id'),
            'device_id' => $fixedDeviceId ?? self::queryValue($request, 'device_id'),
            'sensor_type_id' => self::queryValue($request, 'sensor_type_id'),
        ];
    }

    private static function queryValue(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value === '' ? null : $value;
    }

    private function appendFilters(array &$where, array &$bindings, array $filters, string $sensorAlias, string $typeAlias): void
    {
        if ($filters['sensor_id'] !== null) {
            $where[] = "{$sensorAlias}.sensor_id = ?";
            $bindings[] = $filters['sensor_id'];
        }
        if ($filters['device_id'] !== null) {
            $where[] = 'd.id = ?';
            $bindings[] = $filters['device_id'];
        }
        if ($filters['sensor_type_id'] !== null) {
            $where[] = "{$typeAlias}.id = ?";
            $bindings[] = $filters['sensor_type_id'];
        }
    }

    private function storedAggregates(string $interval, array $filters, Carbon $from, Carbon $to, int $limit, int $offset): array
    {
        $where = ["ra.\"interval\" = ?", 'ra.bucket_start >= ?', 'ra.bucket_start < ?'];
        $bindings = [$interval, $from->toIso8601String(), $to->toIso8601String()];
        $this->appendFilters($where, $bindings, $filters, 'ra', 'st');

        $sqlBase = 'FROM reading_aggregates ra
             JOIN sensors s ON s.id = ra.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             LEFT JOIN LATERAL (
                SELECT si.device_id
                FROM sensor_installations si
                WHERE si.sensor_id = ra.sensor_id
                  AND si.installed_at <= ra.bucket_start
                  AND (si.removed_at IS NULL OR si.removed_at > ra.bucket_start)
                ORDER BY si.installed_at DESC
                LIMIT 1
             ) si ON TRUE
             LEFT JOIN devices d ON d.id = si.device_id AND d.deleted_at IS NULL
             WHERE ' . implode(' AND ', $where);

        $total = (int) DB::selectOne('SELECT COUNT(*) AS cnt ' . $sqlBase, $bindings)->cnt;

        $rows = DB::select(
            'SELECT ra.bucket_start, ra."interval",
                    ra.min_value, ra.max_value, ra.avg_value, ra.sum_value,
                    ra.sample_count, ra.quality_count,
                    s.id AS sensor_id, s.serial_number,
                    st.code AS sensor_code, st.name AS sensor_name, st.unit,
                    d.id AS device_id, d.device_code, d.name AS device_name
             ' . $sqlBase . '
             ORDER BY ra.bucket_start ASC, st.code ASC, s.id ASC
             LIMIT ? OFFSET ?',
            [...$bindings, $limit, $offset],
        );

        return [
            'total' => $total,
            'data' => array_map(fn ($r) => [
                'bucket_start' => Carbon::parse($r->bucket_start)->toIso8601ZuluString(),
                'interval' => $r->interval,
                'sensor_id' => (string) $r->sensor_id,
                'sensor_code' => $r->sensor_code,
                'sensor_name' => $r->sensor_name,
                'unit' => $r->unit,
                'device_id' => $r->device_id ? (string) $r->device_id : null,
                'device_code' => $r->device_code,
                'device_name' => $r->device_name,
                'min_value' => self::num($r->min_value),
                'max_value' => self::num($r->max_value),
                'avg_value' => self::num($r->avg_value),
                'sum_value' => self::num($r->sum_value),
                'sample_count' => (int) $r->sample_count,
                'quality_count' => (int) $r->quality_count,
            ], $rows),
        ];
    }

    private function minuteAggregates(array $filters, Carbon $from, Carbon $to, int $limit, int $offset): array
    {
        $where = ['sr.device_time >= ?', 'sr.device_time < ?'];
        $bindings = [$from->toIso8601String(), $to->toIso8601String()];
        if ($filters['sensor_id'] !== null) {
            $where[] = 'sr.sensor_id = ?';
            $bindings[] = $filters['sensor_id'];
        }
        if ($filters['device_id'] !== null) {
            $where[] = 'sr.device_id = ?';
            $bindings[] = $filters['device_id'];
        }
        if ($filters['sensor_type_id'] !== null) {
            $where[] = 'st.id = ?';
            $bindings[] = $filters['sensor_type_id'];
        }

        $groupBy = "date_trunc('minute', sr.device_time), sr.sensor_id, sr.device_id,
                    s.serial_number, st.code, st.name, st.unit, d.device_code, d.name";
        $whereSql = implode(' AND ', $where);

        $total = (int) DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM (
                SELECT 1
                FROM sensor_readings sr
                JOIN sensors s ON s.id = sr.sensor_id AND s.deleted_at IS NULL
                JOIN sensor_types st ON st.id = s.sensor_type_id
                JOIN devices d ON d.id = sr.device_id AND d.deleted_at IS NULL
                WHERE ' . $whereSql . '
                GROUP BY ' . $groupBy . '
             ) sub',
            $bindings,
        )->cnt;

        $rows = DB::select(
            "SELECT date_trunc('minute', sr.device_time) AS bucket_start,
                    MIN(COALESCE(sr.corrected_value, sr.raw_value)) AS min_value,
                    MAX(COALESCE(sr.corrected_value, sr.raw_value)) AS max_value,
                    AVG(COALESCE(sr.corrected_value, sr.raw_value)) AS avg_value,
                    SUM(COALESCE(sr.corrected_value, sr.raw_value)) AS sum_value,
                    COUNT(*)::INTEGER AS sample_count,
                    COUNT(*) FILTER (WHERE sr.quality_flag = 'GOOD')::INTEGER AS quality_count,
                    sr.sensor_id, sr.device_id, s.serial_number,
                    st.code AS sensor_code, st.name AS sensor_name, st.unit,
                    d.device_code, d.name AS device_name
             FROM sensor_readings sr
             JOIN sensors s ON s.id = sr.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             JOIN devices d ON d.id = sr.device_id AND d.deleted_at IS NULL
             WHERE " . $whereSql . '
             GROUP BY ' . $groupBy . '
             ORDER BY bucket_start ASC, st.code ASC, sr.sensor_id ASC
             LIMIT ? OFFSET ?',
            [...$bindings, $limit, $offset],
        );

        return [
            'total' => $total,
            'data' => array_map(fn ($r) => [
                'bucket_start' => Carbon::parse($r->bucket_start)->toIso8601ZuluString(),
                'interval' => '1m',
                'sensor_id' => (string) $r->sensor_id,
                'sensor_code' => $r->sensor_code,
                'sensor_name' => $r->sensor_name,
                'unit' => $r->unit,
                'device_id' => (string) $r->device_id,
                'device_code' => $r->device_code,
                'device_name' => $r->device_name,
                'min_value' => self::num($r->min_value),
                'max_value' => self::num($r->max_value),
                'avg_value' => self::num($r->avg_value),
                'sum_value' => self::num($r->sum_value),
                'sample_count' => (int) $r->sample_count,
                'quality_count' => (int) $r->quality_count,
            ], $rows),
        ];
    }

    private function raw(array $filters, Carbon $from, Carbon $to, int $limit, int $offset): array
    {
        $where = ['sr.device_time >= ?', 'sr.device_time < ?'];
        $bindings = [$from->toIso8601String(), $to->toIso8601String()];
        if ($filters['sensor_id'] !== null) {
            $where[] = 'sr.sensor_id = ?';
            $bindings[] = $filters['sensor_id'];
        }
        if ($filters['device_id'] !== null) {
            $where[] = 'sr.device_id = ?';
            $bindings[] = $filters['device_id'];
        }
        if ($filters['sensor_type_id'] !== null) {
            $where[] = 'st.id = ?';
            $bindings[] = $filters['sensor_type_id'];
        }

        $whereSql = implode(' AND ', $where);
        $fromSql = 'FROM sensor_readings sr
             JOIN sensors s ON s.id = sr.sensor_id AND s.deleted_at IS NULL
             JOIN sensor_types st ON st.id = s.sensor_type_id
             JOIN devices d ON d.id = sr.device_id AND d.deleted_at IS NULL
             WHERE ' . $whereSql;

        $total = (int) DB::selectOne('SELECT COUNT(*) AS cnt ' . $fromSql, $bindings)->cnt;

        $rows = DB::select(
            'SELECT sr.device_time, sr.seq, sr.sensor_id, sr.device_id,
                    sr.raw_value, sr.corrected_value, sr.quality_flag,
                    s.serial_number, st.code AS sensor_code, st.name AS sensor_name, st.unit,
                    d.device_code, d.name AS device_name
             ' . $fromSql . '
             ORDER BY sr.device_time DESC, sr.sensor_id ASC
             LIMIT ? OFFSET ?',
            [...$bindings, $limit, $offset],
        );

        return [
            'total' => $total,
            'data' => array_map(fn ($r) => [
                'device_time' => Carbon::parse($r->device_time)->toIso8601ZuluString(),
                'seq' => (int) $r->seq,
                'sensor_id' => (string) $r->sensor_id,
                'sensor_code' => $r->sensor_code,
                'sensor_name' => $r->sensor_name,
                'unit' => $r->unit,
                'device_id' => (string) $r->device_id,
                'device_code' => $r->device_code,
                'device_name' => $r->device_name,
                'raw_value' => self::num($r->raw_value),
                'corrected_value' => self::num($r->corrected_value),
                'quality_flag' => $r->quality_flag,
            ], $rows),
        ];
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw ApiException::badRequest('invalid timestamp: expected ISO 8601 or unix seconds');
        }
    }

    private static function lowest(?float $current, mixed $value): ?float
    {
        if ($value === null) {
            return $current;
        }

        $candidate = (float) $value;

        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private static function highest(?float $current, mixed $value): ?float
    {
        if ($value === null) {
            return $current;
        }

        $candidate = (float) $value;

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    private static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}