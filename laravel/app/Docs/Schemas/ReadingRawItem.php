<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReadingRawItem',
    required: ['device_time', 'seq', 'sensor_id', 'sensor_code', 'device_id', 'quality_flag'],
    properties: [
        new OA\Property(property: 'device_time', type: 'string', format: 'date-time'),
        new OA\Property(property: 'seq', type: 'integer', format: 'int64'),
        new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sensor_code', type: 'string'),
        new OA\Property(property: 'sensor_name', type: 'string'),
        new OA\Property(property: 'unit', type: 'string'),
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'device_code', type: 'string'),
        new OA\Property(property: 'device_name', type: 'string'),
        new OA\Property(property: 'raw_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'corrected_value', type: 'number', format: 'double', nullable: true),
        new OA\Property(
            property: 'quality_flag',
            type: 'string',
            enum: ['GOOD', 'OUT_OF_RANGE', 'SENSOR_ERROR', 'CLOCK_DRIFT', 'LATE', 'INVALID', 'DUPLICATE', 'RAIN_INITIAL', 'RAIN_RESET'],
        ),
    ],
)]
class ReadingRawItem
{
}