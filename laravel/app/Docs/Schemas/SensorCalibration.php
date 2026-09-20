<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SensorCalibration',
    required: ['id', 'sensor_id', 'offset', 'scale', 'effective_from'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'offset', type: 'number', format: 'double'),
        new OA\Property(property: 'scale', type: 'number', format: 'double'),
        new OA\Property(property: 'effective_from', type: 'string', format: 'date-time'),
        new OA\Property(property: 'effective_to', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class SensorCalibration
{
}