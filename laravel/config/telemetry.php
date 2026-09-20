<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry ingest settings
    |--------------------------------------------------------------------------
    */

    'max_batch_size' => (int) env('MAX_BATCH_SIZE', 100),

    // Batas item per request batch (mencegah abuse); tetap di-chunk ke job 100.
    'max_request_items' => (int) env('MAX_REQUEST_ITEMS', 5000),

    'queue' => env('RABBITMQ_QUEUE', 'telemetry.ingest'),

    // Rate limit per-device untuk endpoint ingest (permintaan/menit).
    'rate_limit_per_minute' => (int) env('INGEST_RATE_LIMIT_PER_MINUTE', 60),

    // Time-series: rentang maksimal yang boleh diminta dalam bentuk RAW
    // (di luar itu agregasi dipaksa — lihat ReadingController::index).
    'ts_raw_max_span_hours' => (int) env('TS_RAW_MAX_SPAN_HOURS', 24),

    // Time-series interval 1m dihitung on-the-fly; batasi rentang agar wajar.
    'ts_1m_max_span_hours' => (int) env('TS_1M_MAX_SPAN_HOURS', 14 * 24),

    // Maksimal titik per halaman time-series.
    'ts_max_limit' => (int) env('TS_MAX_LIMIT', 1000),

];