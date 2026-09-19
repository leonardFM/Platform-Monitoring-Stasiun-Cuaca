<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $middleware->alias([
            'auth.api_key' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('healthz') || $request->expectsJson(),
        );

        $exceptions->render(function (ApiException $e, Request $request): JsonResponse {
            return new JsonResponse(
                ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]],
                $e->status,
            );
        });

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->is('healthz') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

            if ($status >= 500) {
                report($e);
            }

            $code = match (true) {
                $e instanceof NotFoundHttpException => 'not_found',
                $e instanceof HttpException && $status === 405 => 'method_not_allowed',
                $e instanceof HttpException && $status === 400 => 'bad_request',
                default => 'internal',
            };

            $message = $status >= 500
                ? 'internal server error'
                : ($e->getMessage() ?: 'invalid request');

            return new JsonResponse(
                ['error' => ['code' => $code, 'message' => $message]],
                $status,
            );
        });
    })->create();