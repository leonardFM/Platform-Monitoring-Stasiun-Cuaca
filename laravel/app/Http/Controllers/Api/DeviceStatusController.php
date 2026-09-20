<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\DeviceStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'devices', description: 'Device status & lifecycle')]
final class DeviceStatusController
{
    private const VALID_TRANSITIONS = [
        'provisioned' => ['active', 'decommissioned'],
        'active' => ['maintenance', 'decommissioned'],
        'maintenance' => ['active', 'decommissioned'],
        'decommissioned' => [],
    ];

    #[OA\Post(
        path: '/api/v1/devices/{id}/status',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(
                        property: 'status',
                        type: 'string',
                        enum: ['provisioned', 'active', 'maintenance', 'decommissioned'],
                        example: 'active'
                    ),
                    new OA\Property(property: 'reason', type: 'string', example: 'Deployed to field'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '200', description: 'Status updated', content: new OA\JsonContent(ref: '#/components/schemas/DeviceStatusResponse')),
            new OA\Response(response: '400', description: 'Invalid transition'),
            new OA\Response(response: '404', description: 'Device not found'),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:provisioned,active,maintenance,decommissioned',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        $newStatus = $request->status;
        $currentStatus = $device->status;

        if ($currentStatus === $newStatus) {
            throw ApiException::badRequest("Device is already {$newStatus}");
        }

        if (! isset(self::VALID_TRANSITIONS[$currentStatus]) ||
            ! in_array($newStatus, self::VALID_TRANSITIONS[$currentStatus], true)) {
            throw ApiException::badRequest(
                "Invalid status transition: {$currentStatus} -> {$newStatus}. " .
                "Allowed: " . implode(', ', self::VALID_TRANSITIONS[$currentStatus] ?? [])
            );
        }

        return DB::transaction(function () use ($device, $newStatus, $request, $currentStatus) {
            $now = now();
            $history = DeviceStatusHistory::create([
                'device_id' => $device->id,
                'from_status' => $currentStatus,
                'to_status' => $newStatus,
                'reason' => $request->reason,
                'changed_by' => null, // Could be auth user in real app
                'created_at' => $now,
            ]);

            $device->status = $newStatus;
            $device->save();

            return response()->json([
                'device_id' => (string) $device->id,
                'previous_status' => $currentStatus,
                'current_status' => $newStatus,
                'reason' => $request->reason,
                'changed_at' => $now->toIso8601ZuluString(),
            ]);
        });
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}/status/history',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Status history'),
            new OA\Response(response: '404', description: 'Device not found'),
        ]
    )]
    public function history(string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $history = DeviceStatusHistory::where('device_id', $device->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($h) {
                return [
                    'id' => (string) $h->id,
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'reason' => $h->reason,
                    'changed_at' => $h->created_at->toIso8601ZuluString(),
                ];
            });

        return response()->json(['data' => $history]);
    }
}