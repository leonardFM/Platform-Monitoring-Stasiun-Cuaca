<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Sensor;
use App\Models\SensorInstallation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'sensors', description: 'Sensor installation lifecycle')]
final class SensorInstallationController
{
    #[OA\Post(
        path: '/api/v1/sensors/{id}/install',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['device_id'],
                properties: [
                    new OA\Property(property: 'device_id', type: 'string', format: 'uuid', description: 'Device the sensor is installed on'),
                    new OA\Property(property: 'installed_at', type: 'string', format: 'date-time', description: 'Defaults to now'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '201', description: 'Sensor installed', content: new OA\JsonContent(ref: '#/components/schemas/SensorInstallation')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'Sensor or device not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '409', description: 'Sensor already has an active installation', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function install(Request $request, string $sensor): JsonResponse
    {
        $sensorModel = Sensor::find($sensor);

        if (! $sensorModel) {
            throw ApiException::notFound('Sensor not found');
        }

        $validator = Validator::make($request->all(), [
            'device_id' => 'required|uuid|exists:devices,id',
            'installed_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        $alreadyActive = SensorInstallation::where('sensor_id', $sensorModel->id)
            ->whereNull('removed_at')
            ->exists();

        if ($alreadyActive) {
            throw ApiException::conflict('Sensor already has an active installation');
        }

        $installation = SensorInstallation::create([
            'sensor_id' => $sensorModel->id,
            'device_id' => $request->device_id,
            'installed_at' => $request->installed_at ?? now(),
            'removed_at' => null,
            'created_at' => now(),
        ]);

        return response()->json($this->formatInstallation($installation->refresh()), 201);
    }

    #[OA\Post(
        path: '/api/v1/sensors/{id}/uninstall',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'removed_at', type: 'string', format: 'date-time', description: 'Defaults to now'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '200', description: 'Sensor uninstalled', content: new OA\JsonContent(ref: '#/components/schemas/SensorInstallation')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'No active installation', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function uninstall(Request $request, string $sensor): JsonResponse
    {
        $sensorModel = Sensor::find($sensor);

        if (! $sensorModel) {
            throw ApiException::notFound('Sensor not found');
        }

        $validator = Validator::make($request->all(), [
            'removed_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        $installation = SensorInstallation::where('sensor_id', $sensorModel->id)
            ->whereNull('removed_at')
            ->latest('installed_at')
            ->first();

        if (! $installation) {
            throw ApiException::notFound('Sensor has no active installation');
        }

        $installation->removed_at = $request->removed_at ?? now();
        $installation->save();

        return response()->json($this->formatInstallation($installation->refresh()));
    }

    #[OA\Get(
        path: '/api/v1/sensors/{id}/installations',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Installation history for a sensor', content: new OA\JsonContent(ref: '#/components/schemas/SensorInstallationListResponse')),
            new OA\Response(response: '404', description: 'Sensor not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function index(string $sensor): JsonResponse
    {
        $sensorModel = Sensor::find($sensor);

        if (! $sensorModel) {
            throw ApiException::notFound('Sensor not found');
        }

        $installations = SensorInstallation::where('sensor_id', $sensorModel->id)
            ->orderBy('installed_at', 'desc')
            ->get()
            ->map(fn ($i) => $this->formatInstallation($i));

        return response()->json(['data' => $installations]);
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}/sensors',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Sensors currently installed on a device', content: new OA\JsonContent(ref: '#/components/schemas/SensorInstallationListResponse')),
            new OA\Response(response: '404', description: 'Device not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function deviceIndex(string $device): JsonResponse
    {
        $deviceModel = Device::find($device);

        if (! $deviceModel) {
            throw ApiException::notFound('Device not found');
        }

        $installations = SensorInstallation::with('sensor.sensorType')
            ->where('device_id', $deviceModel->id)
            ->whereNull('removed_at')
            ->orderBy('installed_at', 'desc')
            ->get()
            ->map(function (SensorInstallation $i) {
                $sensor = $i->sensor;
                $type = $sensor?->sensorType;

                return [
                    'sensor_id' => (string) $sensor->id,
                    'serial_number' => $sensor->serial_number,
                    'sensor_type' => $type ? [
                        'id' => (string) $type->id,
                        'code' => $type->code,
                        'name' => $type->name,
                        'unit' => $type->unit,
                    ] : null,
                    'status' => $sensor->status,
                    'installed_at' => $i->installed_at->toIso8601ZuluString(),
                ];
            });

        return response()->json(['data' => $installations]);
    }

    private function formatInstallation(SensorInstallation $installation): array
    {
        return [
            'id' => (string) $installation->id,
            'sensor_id' => (string) $installation->sensor_id,
            'device_id' => (string) $installation->device_id,
            'installed_at' => $installation->installed_at->toIso8601ZuluString(),
            'removed_at' => $installation->removed_at?->toIso8601ZuluString(),
            'created_at' => $installation->created_at?->toIso8601ZuluString(),
        ];
    }
}