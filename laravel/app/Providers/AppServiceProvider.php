<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\IngestRateLimit;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ResolveDeviceContext;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('request_id', RequestId::class);
        $router->aliasMiddleware('auth.api_key', AuthenticateApiKey::class);
        $router->aliasMiddleware('resolve.device_context', ResolveDeviceContext::class);
        $router->aliasMiddleware('ingest.rate_limit', IngestRateLimit::class);
    }
}
