<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Jobs\ProcessTelemetryReading;
use App\Services\JsonParser;
use App\Models\DeviceHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

final class IngestController
{
    #[OA\Post(
        path: '/api/v1/ingest/telemetry',
        tags: ['ingest'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(
                name: 'X-API-Key',
                in: 'header',
                required: true,
                description: 'Device API key. The demo key below is pre-filled for Try-it-out; delete the value to test the 401 path.',
                example: 'dev_demo_weather_station_2024',
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: [
                    'device_id' => 'WS-GRT-001',
                    'fw' => '1.4.2',
                    'ts' => 1757308800,
                    'seq' => 10432,
                    'battery_v' => 3.92,
                    'rssi' => -71,
                    'readings' => [
                        ['s' => 'temp_air', 'v' => 27.4],
                        ['s' => 'humidity', 'v' => 82.1],
                        ['s' => 'pressure', 'v' => 1008.3],
                        ['s' => 'wind_speed', 'v' => 3.2],
                        ['s' => 'wind_dir', 'v' => 217],
                        ['s' => 'rain_counter', 'v' => 1043],
                        ['s' => 'solar_rad', 'v' => 512.7],
                    ],
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '202', description: 'Reading accepted for processing', content: new OA\JsonContent(
                example: ['accepted' => true, 'device_id' => 'WS-GRT-001', 'ts' => 1757308800, 'seq' => 10432],
            )),
            new OA\Response(response: '400', description: 'Invalid payload', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Missing or invalid X-API-Key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '503', description: 'Broker unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $payload = JsonParser::parseSingle($request);
        $device = $request->attributes->get('device');

        $this->assertDeviceMatches($device, $payload['device_id']);

        $this->dispatch($device, $payload['fw'], [[
            'ts' => $payload['ts'],
            'seq' => $payload['seq'],
            'battery_v' => $payload['battery_v'],
            'rssi' => $payload['rssi'],
            'readings' => $payload['readings'],
        ]]);

        return response()->json([
            'accepted' => true,
            'device_id' => $payload['device_id'],
            'ts' => $payload['ts']->getTimestamp(),
            'seq' => $payload['seq'],
        ], 202);
    }

    #[OA\Post(
        path: '/api/v1/ingest/telemetry/batch',
        tags: ['ingest'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(
                name: 'X-API-Key',
                in: 'header',
                required: true,
                description: 'Device API key. The demo key below is pre-filled for Try-it-out; delete the value to test the 401 path.',
                example: 'dev_demo_weather_station_2024',
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: [
                    'device_id' => 'WS-GRT-001',
                    'fw' => '1.4.2',
                    'batch' => [
                        ['ts' => 1757308800, 'seq' => 10432, 'battery_v' => 3.92, 'rssi' => -71, 'readings' => [['s' => 'temp_air', 'v' => 27.4]]],
                        ['ts' => 1757308860, 'seq' => 10433, 'battery_v' => 3.91, 'rssi' => -73, 'readings' => [['s' => 'temp_air', 'v' => 27.6]]],
                    ],
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '202', description: 'Batch accepted for processing (chunks dispatched to the queue)', content: new OA\JsonContent(
                example: ['accepted' => 10, 'duplicates' => 2, 'items' => [['index' => 0, 'status' => 'accepted'], ['index' => 1, 'status' => 'duplicate']]],
            )),
            new OA\Response(response: '400', description: 'Invalid payload or empty batch', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Missing or invalid X-API-Key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '413', description: 'Batch too large', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '503', description: 'Broker unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function batch(Request $request): JsonResponse
    {
        $payload = JsonParser::parseBatch($request);
        $device = $request->attributes->get('device');

        if (count($payload['items']) === 0) {
            throw ApiException::badRequest('payload.batch must contain at least one reading');
        }

        $maxRequestItems = (int) config('telemetry.max_request_items', 5000);
        if (count($payload['items']) > $maxRequestItems) {
            throw ApiException::payloadTooLarge(
                sprintf('batch size %d exceeds limit %d', count($payload['items']), $maxRequestItems),
            );
        }

        $this->assertDeviceMatches($device, $payload['device_id']);

        // Best-effort deteksi duplikat terhadap baris yang sudah ter-persist.
        $pending = [];          // yang diteruskan ke queue
        $duplicates = 0;
        $statuses = [];

        foreach ($payload['items'] as $index => $item) {
            $isDup = $this->alreadyPersisted($device->id, $item);
            $status = $isDup ? 'duplicate' : 'accepted';
            $statuses[] = ['index' => $index, 'status' => $status];

            if ($isDup) {
                $duplicates++;
                continue;
            }

            $pending[] = [
                'ts' => $item['ts'],
                'seq' => $item['seq'],
                'battery_v' => $item['battery_v'],
                'rssi' => $item['rssi'],
                'readings' => $item['readings'],
            ];
        }

        // F.3#8: batch > batas chunking -> pecah jadi beberapa job.
        $chunkSize = (int) config('telemetry.max_batch_size', 100);
        foreach (array_chunk($pending, $chunkSize) as $chunkItems) {
            $this->dispatch($device, $payload['fw'], $chunkItems);
        }

        Log::info('telemetry batch accepted', [
            'device_id' => $device->id,
            'accepted' => count($pending),
            'duplicates' => $duplicates,
        ]);

        return response()->json([
            'accepted' => count($pending),
            'duplicates' => $duplicates,
            'items' => $statuses,
        ], 202);
    }

    #[OA\Post(
        path: '/api/v1/ingest/heartbeat',
        tags: ['ingest'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(
                name: 'X-API-Key',
                in: 'header',
                required: true,
                description: 'Device API key. The demo key below is pre-filled for Try-it-out; delete the value to test the 401 path.',
                example: 'dev_demo_weather_station_2024',
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                example: [
                    'device_id' => 'WS-GRT-001',
                    'ts' => 1757308920,
                    'fw' => '1.4.2',
                    'battery_v' => 3.90,
                    'rssi' => -70,
                    'uptime_s' => 864321,
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Heartbeat recorded', content: new OA\JsonContent(
                example: ['accepted' => true, 'device_id' => 'WS-GRT-001', 'ts' => 1757308920],
            )),
            new OA\Response(response: '400', description: 'Invalid payload', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Missing or invalid X-API-Key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function heartbeat(Request $request): JsonResponse
    {
        $payload = JsonParser::parseHeartbeat($request);
        $device = $request->attributes->get('device');

        $this->assertDeviceMatches($device, $payload['device_id']);

        DB::transaction(function () use ($device, $payload): void {
            DeviceHealth::updateOrCreate(
                ['device_id' => $device->id],
                [
                    'battery_voltage' => $payload['battery_v'],
                    'rssi' => $payload['rssi'],
                    'firmware_version' => $payload['fw'] ?? $device->firmware_version,
                    'last_heartbeat_at' => now(),
                    'uptime_seconds' => $payload['uptime_s'],
                    'updated_at' => now(),
                ]
            );

            DB::update(
                'UPDATE devices SET last_seen_at = now(), firmware_version = COALESCE(?, firmware_version) WHERE id = ?',
                [$payload['fw'], $device->id],
            );
        });

        return response()->json([
            'accepted' => true,
            'device_id' => $payload['device_id'],
            'ts' => $payload['ts']->getTimestamp(),
        ]);
    }

    /**
     * Payload.device_id harus cocok dengan device yang terautentikasi
     * (device_code atau UUID) — mencegah payload silang device.
     */
    private function assertDeviceMatches(object $device, string $payloadDeviceId): void
    {
        if ($payloadDeviceId !== $device->device_code && $payloadDeviceId !== $device->id) {
            throw ApiException::badRequest(
                sprintf("payload device_id '%s' does not match authenticated device", $payloadDeviceId),
            );
        }
    }

    /**
     * Best-effort: payload (device, ts, seq) sudah pernah dipersist?
     */
    private function alreadyPersisted(string $deviceId, array $item): bool
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM sensor_readings WHERE device_id = ? AND device_time = ? AND seq = ? LIMIT 1',
            [$deviceId, $item['ts']->toIso8601String(), $item['seq']],
        );

        return $exists !== null;
    }

    private function dispatch(object $device, ?string $fw, array $items): void
    {
        $serialized = array_map(
            fn (array $item): array => array_merge($item, [
                'ts' => $item['ts']->toIso8601String(),
            ]),
            $items,
        );

        ProcessTelemetryReading::dispatch(
            $device->id,
            $fw,
            $serialized,
        )->onConnection('rabbitmq')->onQueue(config('telemetry.queue', 'telemetry.ingest'));

        Log::info('telemetry dispatched', [
            'device_id' => $device->id,
            'items' => count($serialized),
        ]);
    }
}