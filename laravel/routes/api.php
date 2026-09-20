<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceCredentialController;
use App\Http\Controllers\Api\DeviceHealthController;
use App\Http\Controllers\Api\DeviceStatusController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\SensorCalibrationController;
use App\Http\Controllers\Api\SensorController;
use App\Http\Controllers\Api\SensorInstallationController;
use App\Http\Controllers\Api\SensorTypeController;
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

        // Device > installed sensors and device-scoped readings
        Route::get('sensors', [SensorInstallationController::class, 'deviceIndex']);
        Route::get('readings', [ReadingController::class, 'device']);
        Route::get('readings/latest', [ReadingController::class, 'latest']);
    });

    // Sensor Types
    Route::get('sensor-types', [SensorTypeController::class, 'index']);
    Route::post('sensor-types', [SensorTypeController::class, 'store']);
    Route::get('sensor-types/{id}', [SensorTypeController::class, 'show']);
    Route::put('sensor-types/{id}', [SensorTypeController::class, 'update']);

    // Sensor Catalog
    Route::get('sensors', [SensorController::class, 'index']);
    Route::post('sensors', [SensorController::class, 'store']);
    Route::get('sensors/{id}', [SensorController::class, 'show']);
    Route::put('sensors/{id}', [SensorController::class, 'update']);
    Route::delete('sensors/{id}', [SensorController::class, 'destroy']);

    // Sensor sub-resources
    Route::prefix('sensors/{sensor}')->group(function () {
        Route::post('install', [SensorInstallationController::class, 'install']);
        Route::post('uninstall', [SensorInstallationController::class, 'uninstall']);
        Route::get('installations', [SensorInstallationController::class, 'index']);
        Route::get('calibrations', [SensorCalibrationController::class, 'index']);
        Route::post('calibrations', [SensorCalibrationController::class, 'store']);
    });

    // Readings / Time-series
    Route::get('readings/summary', [ReadingController::class, 'summary']);
    Route::get('readings', [ReadingController::class, 'index']);
});