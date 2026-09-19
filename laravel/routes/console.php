<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Self-healing aggregate recalculation: rebuilds the most recent hour/day
 * rollups directly from base readings every five minutes (mirrors the worker's
 * original 300s recalc loop).
 */
Schedule::command('aggregates:recalculate')
    ->everyFiveMinutes()
    ->withoutOverlapping();