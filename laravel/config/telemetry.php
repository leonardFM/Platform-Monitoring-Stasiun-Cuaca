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

];