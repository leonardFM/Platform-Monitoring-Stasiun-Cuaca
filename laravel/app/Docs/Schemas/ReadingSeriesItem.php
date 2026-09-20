<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReadingSeriesItem',
    required: ['bucket_start', 'interval', 'sensor_id', 'sensor_code', 'device_id', 'sample_count'],
    properties: [
        new OA\Property(property: 'bucket_start', type: 'string', format: 'date-time'),
        new OA\Property(property: 'interval', type: 'string', enum: ['1m', '1h', '1d']),
        new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sensor_code', type: 'string'),
        new OA\Property(property: 'sensor_name', type: 'string'),
        new OA\Property(property: 'unit', type: 'string'),
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'device_code', type: 'string', nullable: true),
        new OA\Property(property: 'device_name', type: 'string', nullable: true),
        new OA\Property(property: 'min_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'max_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'avg_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'sum_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'sample_count', type: 'integer'),
        new OA\Property(property: 'quality_count', type: 'integer'),
    ],
)]
class ReadingSeriesItem
{
}