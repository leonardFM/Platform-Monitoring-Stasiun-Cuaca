<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReadingSummaryItem',
    required: ['sensor_id', 'sensor_code', 'sensor_name', 'unit', 'bucket_count', 'sample_count', 'quality_count'],
    properties: [
        new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sensor_code', type: 'string'),
        new OA\Property(property: 'sensor_name', type: 'string'),
        new OA\Property(property: 'unit', type: 'string'),
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'device_name', type: 'string', nullable: true),
        new OA\Property(property: 'bucket_count', type: 'integer'),
        new OA\Property(property: 'min_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'max_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'avg_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'sum_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'sample_count', type: 'integer'),
        new OA\Property(property: 'quality_count', type: 'integer'),
    ],
)]
class ReadingSummaryItem
{
}