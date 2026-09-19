<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry ingest settings
    |--------------------------------------------------------------------------
    */

    'max_batch_size' => (int) env('MAX_BATCH_SIZE', 100),

    'queue' => env('RABBITMQ_QUEUE', 'telemetry.ingest'),

];