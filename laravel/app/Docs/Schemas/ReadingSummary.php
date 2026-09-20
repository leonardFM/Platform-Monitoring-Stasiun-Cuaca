<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReadingSummary',
    required: ['interval', 'from', 'to', 'filters', 'summary', 'by_sensor'],
    properties: [
        new OA\Property(property: 'interval', type: 'string', enum: ['1m', '1h', '1d']),
        new OA\Property(property: 'from', type: 'string', format: 'date-time'),
        new OA\Property(property: 'to', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'filters',
            type: 'object',
            properties: [
                new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'device_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'sensor_type_id', type: 'string', format: 'uuid', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'summary',
            required: ['bucket_count', 'sensor_count', 'sample_count', 'quality_count'],
            properties: [
                new OA\Property(property: 'bucket_count', type: 'integer'),
                new OA\Property(property: 'sensor_count', type: 'integer'),
                new OA\Property(property: 'min_value', type: 'number', format: 'double', nullable: true),
                new OA\Property(property: 'max_value', type: 'number', format: 'double', nullable: true),
                new OA\Property(property: 'avg_value', type: 'number', format: 'double', nullable: true),
                new OA\Property(property: 'sum_value', type: 'number', format: 'double'),
                new OA\Property(property: 'sample_count', type: 'integer'),
                new OA\Property(property: 'quality_count', type: 'integer'),
                new OA\Property(property: 'quality_ratio', type: 'number', format: 'double', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'by_sensor',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ReadingSummaryItem'),
        ),
    ],
)]
class ReadingSummary
{
}