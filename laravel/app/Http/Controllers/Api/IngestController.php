<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Jobs\ProcessTelemetryReading;
use App\Services\JsonParser;
use App\Services\ReadingValidator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                description: "Device API key. The demo key below is pre-filled for Try-it-out; delete the value to test the 401 path.",
                example: 'dev_demo_weather_station_2024',
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TelemetryReading'),
        ),
        responses: [
            new OA\Response(response: '202', description: 'Reading accepted for processing', content: new OA\JsonContent(ref: '#/components/schemas/IngestAccepted')),
            new OA\Response(response: '400', description: 'Invalid payload', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Missing or invalid X-API-Key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '413', description: 'Body exceeds size limit', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '503', description: 'Broker unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $reading = JsonParser::parseReading($request);
        ReadingValidator::validate($reading, Carbon::now());

        $device = $request->attributes->get('device');

        $this->dispatchFor($device->id, $reading);

        return response()->json(['accepted' => true, 'message_id' => $reading['message_id']], 202);
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
                description: "Device API key. The demo key below is pre-filled for Try-it-out; delete the value to test the 401 path.",
                example: 'dev_demo_weather_station_2024',
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/BatchRequest'),
        ),
        responses: [
            new OA\Response(response: '202', description: 'Batch accepted for processing', content: new OA\JsonContent(ref: '#/components/schemas/BatchAccepted')),
            new OA\Response(response: '400', description: 'Invalid payload or empty batch', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Missing or invalid X-API-Key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '413', description: 'Batch too large', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '503', description: 'Broker unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function batch(Request $request): JsonResponse
    {
        $readings = JsonParser::parseBatch($request);

        if (count($readings) === 0) {
            throw ApiException::badRequest('batch must contain at least one reading');
        }

        $maxBatchSize = (int) config('telemetry.max_batch_size', 100);
        if (count($readings) > $maxBatchSize) {
            throw ApiException::payloadTooLarge(
                sprintf('batch size %d exceeds limit %d', count($readings), $maxBatchSize),
            );
        }

        $device = $request->attributes->get('device');
        $messageIds = [];

        foreach ($readings as $reading) {
            ReadingValidator::validate($reading, Carbon::now());
            $this->dispatchFor($device->id, $reading);
            $messageIds[] = $reading['message_id'];
        }

        return response()->json(['accepted' => count($messageIds), 'message_ids' => $messageIds], 202);
    }

    private function dispatchFor(string $deviceId, array $reading): void
    {
        ProcessTelemetryReading::dispatch(
            $deviceId,
            $reading['message_id'],
            $reading['taken_at']->toIso8601String(),
            $reading['sensors'],
        )->onConnection('rabbitmq')->onQueue('telemetry.ingest');

        Log::info('telemetry accepted', [
            'device_id' => $deviceId,
            'message_id' => $reading['message_id'],
        ]);
    }
}