<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/healthz', HealthController::class);