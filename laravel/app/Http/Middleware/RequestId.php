<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;

/**
 * Adds X-Request-Id to every API response and exposes the id on the request
 * attributes so error envelopes can reference it for tracing.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): mixed
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);

        if ($response instanceof Response || $response instanceof SymfonyJsonResponse) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }
}