<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Per-device ingress throttle for the telemetry endpoints.
 * Keyed by the authenticated device (from auth.api_key), window 60s.
 * Rejects with 429 + Retry-After when the per-minute budget is exhausted.
 */
class IngestRateLimit
{
    public function handle(Request $request, Closure $next): mixed
    {
        $device = $request->attributes->get('device');
        $deviceKey = $device ? (string) $device->id : 'anonymous';

        $perMinute = max(1, (int) config('telemetry.rate_limit_per_minute', 60));
        $window = 60;

        $bucket = floor(time() / $window);
        $cacheKey = "ingest:rl:{$deviceKey}:{$bucket}";

        $count = (int) Cache::get($cacheKey, 0);
        if ($count >= $perMinute) {
            $retryIn = (int) ($bucket + 1) * $window - time();

            return response()->json(
                ['error' => ['code' => 'too_many_requests', 'message' => 'ingest rate limit exceeded']],
                429,
                ['Retry-After' => max(1, $retryIn)],
            );
        }

        Cache::put($cacheKey, $count + 1, $window);

        return $next($request);
    }
}