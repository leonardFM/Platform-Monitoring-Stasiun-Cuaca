<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

final class HealthController
{
    #[OA\Get(
        path: '/healthz',
        tags: ['system'],
        responses: [
            new OA\Response(response: '200', description: 'Service is healthy', content: new OA\JsonContent(ref: '#/components/schemas/HealthzResponse')),
            new OA\Response(response: '503', description: 'Postgres unreachable'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json(['status' => 'ok']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'degraded'], 503);
        }
    }
}