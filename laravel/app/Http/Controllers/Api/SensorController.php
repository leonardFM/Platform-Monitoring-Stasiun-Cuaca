<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Sensor;
use App\Models\SensorInstallation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'sensors', description: 'Sensor catalog management')]
final class SensorController
{
    #[OA\Get(
        path: '/api/v1/sensors',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'sensor_type_id', in: 'query', description: 'Filter by sensor type UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'serial_number', in: 'query', description: 'Filter by serial number', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'maintenance', 'retired'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Sensor list', content: new OA\JsonContent(ref: '#/components/schemas/SensorListResponse')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Sensor::with('sensorType');

        if ($request->has('sensor_type_id')) {
            $query->where('sensor_type_id', $request->sensor_type_id);
        }
        if ($request->has('serial_number')) {
            $query->where('serial_number', 'like', '%' . $request->serial_number . '%');
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $sensors = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $sensors->getCollection()->map(fn ($s) => $this->formatSensor($s)),
            'pagination' => [
                'current_page' => $sensors->currentPage(),
                'last_page' => $sensors->lastPage(),
                'per_page' => $sensors->perPage(),
                'total' => $sensors->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/sensors',
        tags: ['sensors'],
        security: [['api_key' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['serial_number', 'sensor_type_id', 'status'],
                properties: [
                    new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, example: 'SN-TMP-2024-0001'),
                    new OA\Property(property: 'sensor_type_id', type: 'string', format: 'uuid', example: 'b0000000-0000-4000-8000-000000000001'),
                    new OA\Property(property: 'manufacturer', type: 'string', maxLength: 255, example: 'Demo Instruments'),
                    new OA\Property(property: 'model', type: 'string', maxLength: 255, example: 'D-Series'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'maintenance', 'retired'], example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '201', description: 'Sensor created', content: new OA\JsonContent(ref: '#/components/schemas/Sensor')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '409', description: 'Serial number exists', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'serial_number' => 'required|string|max:100|unique:sensors,serial_number',
            'sensor_type_id' => 'required|uuid|exists:sensor_types,id',
            'manufacturer' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'status' => 'required|string|in:active,inactive,maintenance,retired',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        $sensor = Sensor::create([
            'serial_number' => $request->serial_number,
            'sensor_type_id' => $request->sensor_type_id,
            'manufacturer' => $request->manufacturer,
            'model' => $request->model,
            'status' => $request->status,
        ]);

        return response()->json($this->formatSensor($sensor->fresh('sensorType')), 201);
    }

    #[OA\Get(
        path: '/api/v1/sensors/{id}',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Sensor details', content: new OA\JsonContent(ref: '#/components/schemas/Sensor')),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $sensor = Sensor::with('sensorType')->find($id);

        if (! $sensor) {
            throw ApiException::notFound('Sensor not found');
        }

        return response()->json($this->formatSensor($sensor));
    }

    #[OA\Put(
        path: '/api/v1/sensors/{id}',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'serial_number', type: 'string', maxLength: 100),
                    new OA\Property(property: 'sensor_type_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'manufacturer', type: 'string', maxLength: 255),
                    new OA\Property(property: 'model', type: 'string', maxLength: 255),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'maintenance', 'retired']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '200', description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Sensor')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $sensor = Sensor::find($id);

        if (! $sensor) {
            throw ApiException::notFound('Sensor not found');
        }

        $validator = Validator::make($request->all(), [
            'serial_number' => 'sometimes|string|max:100|unique:sensors,serial_number,' . $sensor->id,
            'sensor_type_id' => 'sometimes|uuid|exists:sensor_types,id',
            'manufacturer' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'status' => 'sometimes|string|in:active,inactive,maintenance,retired',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        if ($request->has('serial_number')) {
            $sensor->serial_number = $request->serial_number;
        }
        if ($request->has('sensor_type_id')) {
            $sensor->sensor_type_id = $request->sensor_type_id;
        }
        if ($request->has('manufacturer')) {
            $sensor->manufacturer = $request->manufacturer;
        }
        if ($request->has('model')) {
            $sensor->model = $request->model;
        }
        if ($request->has('status')) {
            $sensor->status = $request->status;
        }
        $sensor->save();

        return response()->json($this->formatSensor($sensor->fresh('sensorType')));
    }

    #[OA\Delete(
        path: '/api/v1/sensors/{id}',
        tags: ['sensors'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Sensor soft-deleted'),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '409', description: 'Sensor has an active installation', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $sensor = Sensor::find($id);

        if (! $sensor) {
            throw ApiException::notFound('Sensor not found');
        }

        $hasActiveInstallation = SensorInstallation::where('sensor_id', $sensor->id)
            ->whereNull('removed_at')
            ->exists();

        if ($hasActiveInstallation) {
            throw ApiException::conflict('Cannot delete a sensor with an active installation');
        }

        $sensor->delete();

        return response()->json(null, 204);
    }

    private function formatSensor(Sensor $sensor): array
    {
        $type = $sensor->sensorType;

        return [
            'id' => (string) $sensor->id,
            'serial_number' => $sensor->serial_number,
            'sensor_type' => $type ? [
                'id' => (string) $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'unit' => $type->unit,
            ] : null,
            'manufacturer' => $sensor->manufacturer,
            'model' => $sensor->model,
            'status' => $sensor->status,
            'created_at' => $sensor->created_at->toIso8601ZuluString(),
            'updated_at' => $sensor->updated_at->toIso8601ZuluString(),
            'deleted_at' => $sensor->deleted_at?->toIso8601ZuluString(),
        ];
    }
}