<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceCredentialController;
use App\Http\Controllers\Api\DeviceHealthController;
use App\Http\Controllers\Api\DeviceStatusController;
use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', DashboardController::class);

Route::middleware('auth.api_key')->group(function () {
    Route::post('/ingest/telemetry', [IngestController::class, 'store']);
    Route::post('/ingest/telemetry/batch', [IngestController::class, 'batch']);
    Route::post('/ingest/heartbeat', [IngestController::class, 'heartbeat']);

    // Device Stale - use a different path to avoid conflict
    Route::get('devices/monitoring/stale', [DeviceHealthController::class, 'stale']);

    // Device Management
    Route::apiResource('devices', DeviceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // Device Credentials
    Route::prefix('devices/{device}')->group(function () {
        Route::get('credentials', [DeviceCredentialController::class, 'index']);
        Route::post('credentials', [DeviceCredentialController::class, 'store']);
        Route::post('credentials/rotate', [DeviceCredentialController::class, 'rotate']);
        Route::delete('credentials/{credential}', [DeviceCredentialController::class, 'revoke']);

        // Device Status
        Route::post('status', [DeviceStatusController::class, 'update']);
        Route::get('status/history', [DeviceStatusController::class, 'history']);

        // Device Health
        Route::post('heartbeat', [DeviceHealthController::class, 'heartbeat']);
        Route::get('health', [DeviceHealthController::class, 'show']);
    });
});