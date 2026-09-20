<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Sensor;
use App\Models\SensorCalibration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'sensors', description: 'Sensor calibration records')]
final class SensorCalibrationController
{
    #[OA\Get(
        path: '/api/v1/sensors/{id}/calibrations',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Calibration history for a sensor', content: new OA\JsonContent(ref: '#/components/schemas/SensorCalibrationListResponse')),
            new OA\Response(response: '404', description: 'Sensor not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function index(string $sensor): JsonResponse
    {
        $sensorModel = Sensor::find($sensor);

        if (! $sensorModel) {
            throw ApiException::notFound('Sensor not found');
        }

        $calibrations = SensorCalibration::where('sensor_id', $sensorModel->id)
            ->orderBy('effective_from', 'desc')
            ->get()
            ->map(fn ($c) => $this->formatCalibration($c));

        return response()->json(['data' => $calibrations]);
    }

    #[OA\Post(
        path: '/api/v1/sensors/{id}/calibrations',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'offset', type: 'number', format: 'double', default: 0, example: 0.5),
                    new OA\Property(property: 'scale', type: 'number', format: 'double', default: 1, example: 1.02),
                    new OA\Property(property: 'effective_from', type: 'string', format: 'date-time', description: 'Defaults to now'),
                    new OA\Property(property: 'effective_to', type: 'string', format: 'date-time', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '201', description: 'Calibration recorded', content: new OA\JsonContent(ref: '#/components/schemas/SensorCalibration')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'Sensor not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function store(Request $request, string $sensor): JsonResponse
    {
        $sensorModel = Sensor::find($sensor);

        if (! $sensorModel) {
            throw ApiException::notFound('Sensor not found');
        }

        $validator = Validator::make($request->all(), [
            'offset' => 'nullable|numeric',
            'scale' => 'nullable|numeric',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after:effective_from',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        $calibration = SensorCalibration::create([
            'sensor_id' => $sensorModel->id,
            'offset' => $request->offset ?? 0,
            'scale' => $request->scale ?? 1,
            'effective_from' => $request->effective_from ?? now(),
            'effective_to' => $request->effective_to,
            'created_at' => now(),
        ]);

        return response()->json($this->formatCalibration($calibration->refresh()), 201);
    }

    private function formatCalibration(SensorCalibration $calibration): array
    {
        return [
            'id' => (string) $calibration->id,
            'sensor_id' => (string) $calibration->sensor_id,
            'offset' => (float) $calibration->offset,
            'scale' => (float) $calibration->scale,
            'effective_from' => $calibration->effective_from->toIso8601ZuluString(),
            'effective_to' => $calibration->effective_to?->toIso8601ZuluString(),
            'created_at' => $calibration->created_at?->toIso8601ZuluString(),
        ];
    }
}