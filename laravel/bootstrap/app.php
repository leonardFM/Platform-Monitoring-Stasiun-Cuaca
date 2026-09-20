<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\IngestRateLimit;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ResolveDeviceContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tracing id di setiap response pakai file cache (tanpa Redis).
        $middleware->alias([
            'request_id' => RequestId::class,
        ]);

        // Auth khusus device (X-API-Key -> device_credentials).
        $middleware->alias(['auth.api_key' => AuthenticateApiKey::class]);

        // Resolusi context resource ({device}/{sensor}/kalibrasi) + ingest key.
        $middleware->alias(['resolve.device_context' => ResolveDeviceContext::class]);

        // Rate limiting ingest per device (cache file, 1 worker).
        $middleware->alias(['ingest.rate_limit' => IngestRateLimit::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('healthz') || $request->expectsJson(),
        );

        $exceptions->render(function (ApiException $e, Request $request): JsonResponse {
            $requestId = $request->attributes->get('request_id') ?? (string) Str::uuid();

            return response()->json(
                [
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                        'request_id' => $requestId,
                    ],
                    'request_id' => $requestId,
                ],
                $e->status,
                ['X-Request-Id' => $requestId],
            );
        });

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->is('healthz') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
            $requestId = $request->attributes->get('request_id') ?? (string) Str::uuid();

            if ($status === 429) {
                $code = 'too_many_requests';
                $message = 'rate limit exceeded';
            } elseif ($status >= 500) {
                report($e);
                $code = 'internal';
                $message = 'internal server error';
            } else {
                $code = match (true) {
                    $e instanceof NotFoundHttpException => 'not_found',
                    $e instanceof HttpException && $status === 405 => 'method_not_allowed',
                    $e instanceof HttpException && $status === 400 => 'bad_request',
                    default => 'bad_request',
                };
                $message = $e->getMessage() ?: 'invalid request';
            }

            return response()->json(
                ['error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId], 'request_id' => $requestId],
                $status,
                ['X-Request-Id' => $requestId],
            );
        });
    })
    ->create();