<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\DeviceCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'devices', description: 'Device credentials management')]
final class DeviceCredentialController
{
    #[OA\Post(
        path: '/api/v1/devices/{id}/credentials',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'api_key', type: 'string', example: 'ws_abc123...'),
                    new OA\Property(property: 'secret', type: 'string', description: 'Plain secret (returned only once)', example: 'sk_live_abc123...'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: '201', description: 'Credentials created', content: new OA\JsonContent(ref: '#/components/schemas/DeviceCredential')),
            new OA\Response(response: '400', description: 'Validation error'),
            new OA\Response(response: '404', description: 'Device not found'),
            new OA\Response(response: '409', description: 'API key exists'),
        ]
    )]
    public function store(Request $request, string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $validator = Validator::make($request->all(), [
            'api_key' => 'sometimes|string|max:255|unique:device_credentials,api_key',
            'secret' => 'sometimes|string|min:32',
        ]);

        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }

        return DB::transaction(function () use ($request, $device) {
            $apiKey = $request->api_key ?? 'ws_' . Str::random(32);
            $plainSecret = $request->secret ?? 'sk_' . Str::random(40);

            $hashedSecret = Crypt::encryptString($plainSecret);
            $now = now();

            $credential = DeviceCredential::create([
                'device_id' => $device->id,
                'api_key' => $apiKey,
                'secret_hash' => $hashedSecret,
                'created_at' => $now,
            ]);

            return response()->json([
                'id' => (string) $credential->id,
                'device_id' => (string) $credential->device_id,
                'api_key' => $credential->api_key,
                'secret' => $plainSecret, // Only returned once!
                'created_at' => $now->toIso8601ZuluString(),
            ], 201);
        });
    }

    #[OA\Get(
        path: '/api/v1/devices/{id}/credentials',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Credential list (secret hidden)', content: new OA\JsonContent(ref: '#/components/schemas/DeviceCredentialListResponse')),
            new OA\Response(response: '404', description: 'Device not found'),
        ]
    )]
    public function index(string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        $credentials = DeviceCredential::where('device_id', $device->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($c) {
                return [
                    'id' => (string) $c->id,
                    'api_key' => $c->api_key,
                    'last_used_at' => $c->last_used_at ? \Carbon\Carbon::parse($c->last_used_at)->toIso8601ZuluString() : null,
                    'revoked_at' => $c->revoked_at ? \Carbon\Carbon::parse($c->revoked_at)->toIso8601ZuluString() : null,
                    'created_at' => \Carbon\Carbon::parse($c->created_at)->toIso8601ZuluString(),
                ];
            });

        return response()->json(['data' => $credentials]);
    }

    #[OA\Delete(
        path: '/api/v1/devices/{deviceId}/credentials/{credentialId}',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'deviceId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'credentialId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Revoked'),
            new OA\Response(response: '404', description: 'Not found'),
        ]
    )]
    public function revoke(string $deviceId, string $credentialId): JsonResponse
    {
        $credential = DeviceCredential::where('device_id', $deviceId)
            ->where('id', $credentialId)
            ->first();

        if (! $credential) {
            throw ApiException::notFound('Credential not found');
        }

        $credential->revoked_at = now();
        $credential->save();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/devices/{id}/credentials/rotate',
        tags: ['devices'],
        security: [['api_key' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '201', description: 'New credentials', content: new OA\JsonContent(ref: '#/components/schemas/DeviceCredential')),
            new OA\Response(response: '404', description: 'Device not found'),
        ]
    )]
    public function rotate(Request $request, string $id): JsonResponse
    {
        $device = Device::find($id);

        if (! $device) {
            throw ApiException::notFound('Device not found');
        }

        return DB::transaction(function () use ($device) {
            // Revoke all existing
            DeviceCredential::where('device_id', $device->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            // Create new
            $apiKey = 'ws_' . Str::random(32);
            $plainSecret = 'sk_' . Str::random(40);
            $hashedSecret = Crypt::encryptString($plainSecret);

            $credential = DeviceCredential::create([
                'device_id' => $device->id,
                'api_key' => $apiKey,
                'secret_hash' => $hashedSecret,
            ]);

            return response()->json([
                'id' => (string) $credential->id,
                'device_id' => (string) $credential->device_id,
                'api_key' => $credential->api_key,
                'secret' => $plainSecret,
                'created_at' => $credential->created_at->toIso8601ZuluString(),
            ], 201);
        });
    }
}