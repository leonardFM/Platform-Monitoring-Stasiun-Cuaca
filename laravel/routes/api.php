<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', DashboardController::class);

Route::middleware('auth.api_key')->group(function () {
    Route::post('/ingest/telemetry', [IngestController::class, 'store']);
    Route::post('/ingest/telemetry/batch', [IngestController::class, 'batch']);
});