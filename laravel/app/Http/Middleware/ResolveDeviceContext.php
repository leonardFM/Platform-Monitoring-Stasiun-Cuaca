<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse as BaseJsonResponse;

/**
 * Mengisi konteks resource dari segmen path {device} (UUID) — dipakai untuk
 * grup `devices/{device}/sensors` dan sub-resource device lain yang butuh
 * device penuh (eager location + status) tanpa re-query berulang.
 *
 * Menetapkan `$request->attributes->set('device_resolved', Device)`.
 */
class ResolveDeviceContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        $deviceId = $request->route('device');

        if (filled($deviceId)) {
            $device = Device::with('locationRelation')
                ->whereKey($deviceId)
                ->whereNull('deleted_at')
                ->first();

            if (! $device) {
                return response()->json(
                    ['error' => ['code' => 'not_found', 'message' => 'Device not found']],
                    404,
                );
            }

            $request->attributes->set('device_resolved', $device);
        }

        return $next($request);
    }
}
