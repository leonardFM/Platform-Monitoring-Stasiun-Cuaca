<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SensorType',
    required: ['id', 'code', 'name', 'unit'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', example: 'temp_air'),
        new OA\Property(property: 'name', type: 'string', example: 'Suhu Udara (Air Temperature)'),
        new OA\Property(property: 'unit', type: 'string', example: '°C'),
        new OA\Property(property: 'valid_min', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'valid_max', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'precision', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class SensorType
{
}