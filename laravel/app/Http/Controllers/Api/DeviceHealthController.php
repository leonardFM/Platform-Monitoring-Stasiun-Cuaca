<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\DeviceHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'devices', description: 'Device health & heartbeat')]
final class DeviceHealthController
{
    #[OA\Post(
        path: '/api/v1/devices/{id}/heartbeat',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'battery_voltage', type: 'number', format: 'double', example: 3.7),
                    new OA\Property(property: 'rssi', type: 'number', format: 'double', example: -75),
                    new OA\Property(property: 'firmware_version', type: 'string', example: '1.0.1'),
                    new OA\Property(property: 'uptime_seconds', type: 'integer', example: 86400),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '200', description: 'Heartbeat recorded', content: new OA\JsonContent(ref: '#/components/schemas/DeviceHealth')),
            new OA\Response(response: '400', description: 'Validation error'),
            new OA\Response(response: '404', description: 'Device not found'),
        ]
    )]
    public function heartbeat(Request $request, string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $validator = Validator::make($request->all(), [
            'battery_voltage' => 'nullable|numeric|between:0,20',
            'rssi' => 'nullable|numeric|between:-120,0',
            'firmware_version' => 'nullable|string|max:50',
            'uptime_seconds' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        return DB::transaction(function () use ($device, $request) {
            $health = DeviceHealth::updateOrCreate(
                ['device_id' => $device->id],
                [
                    'battery_voltage' => $request->battery_voltage,
                    'rssi' => $request->rssi,
                    'firmware_version' => $request->firmware_version ?? $device->firmware_version,
                    'last_heartbeat_at' => now(),
                    'uptime_seconds' => $request->uptime_seconds,
                    'updated_at' => now(),
                ]
            );

            $device->last_seen_at = now();
            if ($request->has('firmware_version')) {
                $device->firmware_version = $request->firmware_version;
            }
            $device->save();

            return response()->json($this->formatHealth($health));
        });
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}/health',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Device health', content: new OA\JsonContent(ref: '#/components/schemas/DeviceHealth')),
            new OA\Response(response: '404', description: 'Device not found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $health = DeviceHealth::where('device_id', $device->id)->first();

        if (! $health) {
            return response()->json([
                'device_id' => (string) $device->id,
                'battery_voltage' => null,
                'rssi' => null,
                'firmware_version' => $device->firmware_version,
                'last_heartbeat_at' => null,
                'uptime_seconds' => null,
                'is_stale' => true,
                'stale_minutes' => null,
            ]);
        }

        return response()->json($this->formatHealth($health));
    }

    #[OA\Get(
        path: '/api/v1/devices/stale',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'threshold_minutes', in: 'query', description: 'Minutes since last heartbeat', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status', schema: new OA\Schema(type: 'string', enum: ['active', 'maintenance'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Stale devices list', content: new OA\JsonContent(ref: '#/components/schemas/StaleDevicesResponse')),
        ]
    )]
    public function stale(Request $request): JsonResponse
    {
        $thresholdMinutes = $request->integer('threshold_minutes', 15);
        $threshold = now()->subMinutes($thresholdMinutes);

        $query = Device::with('health')
            ->where('status', $request->status ?? 'active')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_seen_at')
                  ->orWhere('last_seen_at', '<', $threshold);
            });

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $devices = $query->get()->map(function ($device) use ($threshold) {
            $health = $device->health;
            $lastSeen = $device->last_seen_at;
            $staleMinutes = $lastSeen ? $lastSeen->diffInMinutes(now()) : null;

            return [
                'id' => (string) $device->id,
                'device_code' => $device->device_code,
                'name' => $device->name,
                'status' => $device->status,
                'last_seen_at' => $lastSeen?->toIso8601ZuluString(),
                'stale_minutes' => $staleMinutes,
                'battery_voltage' => $health?->battery_voltage ? (float) $health->battery_voltage : null,
                'rssi' => $health?->rssi ? (float) $health->rssi : null,
                'firmware_version' => $health?->firmware_version ?? $device->firmware_version,
            ];
        });

        return response()->json([
            'threshold_minutes' => $thresholdMinutes,
            'count' => $devices->count(),
            'data' => $devices,
        ]);
    }

    private function formatHealth(DeviceHealth $health): array
    {
        $lastHeartbeat = $health->last_heartbeat_at;
        $staleMinutes = $lastHeartbeat ? $lastHeartbeat->diffInMinutes(now()) : null;

        return [
            'device_id' => (string) $health->device_id,
            'battery_voltage' => $health->battery_voltage ? (float) $health->battery_voltage : null,
            'rssi' => $health->rssi ? (float) $health->rssi : null,
            'firmware_version' => $health->firmware_version,
            'last_heartbeat_at' => $lastHeartbeat?->toIso8601ZuluString(),
            'uptime_seconds' => $health->uptime_seconds,
            'is_stale' => $staleMinutes !== null && $staleMinutes > 15,
            'stale_minutes' => $staleMinutes,
        ];
    }
}