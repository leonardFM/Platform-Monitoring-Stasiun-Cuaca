<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'devices', description: 'Device registration & management')]
final class DeviceController
{
    #[OA\Post(
        path: '/api/v1/devices',
        tags: ['devices'],
        security: [['api_key' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['device_code', 'name', 'location'],
                properties: [
                    new OA\Property(property: 'device_code', type: 'string', maxLength: 100, example: 'WS-001'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Jakarta Pusat Station'),
                    new OA\Property(
                        property: 'location',
                        type: 'object',
                        required: ['name', 'latitude', 'longitude'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'Jakarta Pusat'),
                            new OA\Property(property: 'latitude', type: 'number', format: 'double', example: -6.200000),
                            new OA\Property(property: 'longitude', type: 'number', format: 'double', example: 106.816667),
                            new OA\Property(property: 'altitude', type: 'number', format: 'double', example: 8.0),
                        ]
                    ),
                    new OA\Property(property: 'firmware_version', type: 'string', maxLength: 50, example: '1.0.0'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '201', description: 'Device registered', content: new OA\JsonContent(ref: '#/components/schemas/Device')),
            new OA\Response(response: '400', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: '409', description: 'Device code exists', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_code' => 'required|string|max:100|unique:devices,device_code',
            'name' => 'required|string|max:255',
            'location' => 'required|array',
            'location.name' => 'required|string|max:255',
            'location.latitude' => 'required|numeric|between:-90,90',
            'location.longitude' => 'required|numeric|between:-180,180',
            'location.altitude' => 'nullable|numeric',
            'firmware_version' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        return DB::transaction(function () use ($request) {
            $location = Location::create([
                'name' => $request->location['name'],
                'latitude' => $request->location['latitude'],
                'longitude' => $request->location['longitude'],
                'altitude' => $request->location['altitude'] ?? null,
            ]);

            $device = Device::create([
                'device_code' => $request->device_code,
                'name' => $request->name,
                'location_id' => $location->id,
                'status' => 'provisioned',
                'firmware_version' => $request->firmware_version ?? '1.0.0',
            ]);

            return response()->json($this->formatDevice($device), 201);
        });
    }

    #[OA\Get(
        path: '/api/v1/devices',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status', schema: new OA\Schema(type: 'string', enum: ['provisioned', 'active', 'maintenance', 'decommissioned'])),
            new OA\Parameter(name: 'location_id', in: 'query', description: 'Filter by location UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'q', in: 'query', description: 'Search by device_code or name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Device list', content: new OA\JsonContent(ref: '#/components/schemas/DeviceListResponse')),
            new OA\Response(response: '401', description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Device::with('locationRelation');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('device_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $devices = $query->paginate($perPage);

        $devices->getCollection()->loadMissing('locationRelation');

        return response()->json([
            'data' => $devices->getCollection()->map(fn ($d) => $this->formatDevice($d)),
            'pagination' => [
                'current_page' => $devices->currentPage(),
                'last_page' => $devices->lastPage(),
                'per_page' => $devices->perPage(),
                'total' => $devices->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Device details', content: new OA\JsonContent(ref: '#/components/schemas/Device')),
            new OA\Response(response: '404', description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $device = Device::with('locationRelation')->find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        return response()->json($this->formatDevice($device));
    }

    #[OA\Put(
        path: '/api/v1/devices/{id}',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(
                        property: 'location',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'latitude', type: 'number', format: 'double'),
                            new OA\Property(property: 'longitude', type: 'number', format: 'double'),
                            new OA\Property(property: 'altitude', type: 'number', format: 'double'),
                        ]
                    ),
                    new OA\Property(property: 'firmware_version', type: 'string', maxLength: 50),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '200', description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Device')),
            new OA\Response(response: '404', description: 'Not found'),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'location' => 'sometimes|array',
            'location.name' => 'required_with:location|string|max:255',
            'location.latitude' => 'required_with:location|numeric|between:-90,90',
            'location.longitude' => 'required_with:location|numeric|between:-180,180',
            'location.altitude' => 'nullable|numeric',
            'firmware_version' => 'sometimes|string|max:50',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        return DB::transaction(function () use ($request, $device) {
            if ($request->has('name')) {
                $device->name = $request->name;
            }
            if ($request->has('firmware_version')) {
                $device->firmware_version = $request->firmware_version;
            }
            if ($request->has('location')) {
                $device->locationRelation->update($request->location);
            }
            $device->save();

            return response()->json($this->formatDevice($device->fresh('locationRelation')));
        });
    }

    #[OA\Delete(
        path: '/api/v1/devices/{id}',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(response: '404', description: 'Not found'),
            new OA\Response(response: '409', description: 'Cannot delete active device'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        if ($device->status !== 'decommissioned') {
            throw ApiException::conflict('Only decommissioned devices can be deleted');
        }

        $device->delete();

        return response()->json(null, 204);
    }

    private function formatDevice(Device $device): array
    {
        $location = $device->locationRelation;

        return [
            'id' => (string) $device->id,
            'device_code' => $device->device_code,
            'name' => $device->name,
            'location' => $location ? [
                'id' => (string) $location->id,
                'name' => $location->name,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'altitude' => $location->altitude ? (float) $location->altitude : null,
            ] : null,
            'status' => $device->status,
            'firmware_version' => $device->firmware_version,
            'last_seen_at' => $device->last_seen_at?->toIso8601ZuluString(),
            'created_at' => $device->created_at->toIso8601ZuluString(),
            'updated_at' => $device->updated_at->toIso8601ZuluString(),
        ];
    }
}