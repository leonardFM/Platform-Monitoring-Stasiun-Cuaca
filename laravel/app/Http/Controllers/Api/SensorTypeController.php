<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\SensorType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'sensor-types', description: 'Sensor type catalog')]
final class SensorTypeController
{
    #[OA\Get(
        path: '/api/v1/sensor-types',
        tags: ['sensor-types'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Sensor type list', content: new OA\JsonContent(ref: '#/components/schemas/SensorTypeListResponse')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $types = SensorType::orderBy('code')->paginate($perPage);

        return response()->json([
            'data' => $types->getCollection()->map(fn ($t) => $this->formatType($t)),
            'pagination' => [
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
                'per_page' => $types->perPage(),
                'total' => $types->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/sensor-types',
        tags: ['sensor-types'],
        security: [['api_key' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name', 'unit'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50, example: 'temp_air'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Suhu Udara (Air Temperature)'),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 50, example: '°C'),
                    new OA\Property(property: 'valid_min', type: 'number', format: 'double', example: -100),
                    new OA\Property(property: 'valid_max', type: 'number', format: 'double', example: 100),
                    new OA\Property(property: 'precision', type: 'number', format: 'double', example: 0.1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '201', description: 'Sensor type created', content: new OA\JsonContent(ref: '#/components/schemas/SensorType')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '409', description: 'Sensor type code exists', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:sensor_types,code',
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'valid_min' => 'nullable|numeric',
            'valid_max' => 'nullable|numeric',
            'precision' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        $type = SensorType::create([
            'code' => $request->code,
            'name' => $request->name,
            'unit' => $request->unit,
            'valid_min' => $request->valid_min,
            'valid_max' => $request->valid_max,
            'precision' => $request->precision,
        ]);

        return response()->json($this->formatType($type->refresh()), 201);
    }

    #[OA\Get(
        path: '/api/v1/sensor-types/{id}',
        tags: ['sensor-types'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Sensor type details', content: new OA\JsonContent(ref: '#/components/schemas/SensorType')),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $type = SensorType::find($id);

        if (! $type) {
            throw ApiException::notFound('Sensor type not found');
        }

        return response()->json($this->formatType($type));
    }

    #[OA\Put(
        path: '/api/v1/sensor-types/{id}',
        tags: ['sensor-types'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 50),
                    new OA\Property(property: 'valid_min', type: 'number', format: 'double'),
                    new OA\Property(property: 'valid_max', type: 'number', format: 'double'),
                    new OA\Property(property: 'precision', type: 'number', format: 'double'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '200', description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/SensorType')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $type = SensorType::find($id);

        if (! $type) {
            throw ApiException::notFound('Sensor type not found');
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:50|unique:sensor_types,code,' . $type->id,
            'name' => 'sometimes|string|max:255',
            'unit' => 'sometimes|string|max:50',
            'valid_min' => 'nullable|numeric',
            'valid_max' => 'nullable|numeric',
            'precision' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        if ($request->has('code')) {
            $type->code = $request->code;
        }
        if ($request->has('name')) {
            $type->name = $request->name;
        }
        if ($request->has('unit')) {
            $type->unit = $request->unit;
        }
        if ($request->has('valid_min')) {
            $type->valid_min = $request->valid_min;
        }
        if ($request->has('valid_max')) {
            $type->valid_max = $request->valid_max;
        }
        if ($request->has('precision')) {
            $type->precision = $request->precision;
        }
        $type->save();

        return response()->json($this->formatType($type->refresh()));
    }

    private function formatType(SensorType $type): array
    {
        return [
            'id' => (string) $type->id,
            'code' => $type->code,
            'name' => $type->name,
            'unit' => $type->unit,
            'valid_min' => $type->valid_min !== null ? (float) $type->valid_min : null,
            'valid_max' => $type->valid_max !== null ? (float) $type->valid_max : null,
            'precision' => $type->precision !== null ? (float) $type->precision : null,
            'created_at' => $type->created_at->toIso8601ZuluString(),
            'updated_at' => $type->updated_at->toIso8601ZuluString(),
        ];
    }
}