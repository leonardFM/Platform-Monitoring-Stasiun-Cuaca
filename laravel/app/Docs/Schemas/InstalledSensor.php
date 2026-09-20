<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InstalledSensor',
    required: ['sensor_id', 'serial_number', 'sensor_type', 'status', 'installed_at'],
    properties: [
        new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'serial_number', type: 'string'),
        new OA\Property(
            property: 'sensor_type',
            required: ['id', 'code', 'name', 'unit'],
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'unit', type: 'string'),
            ],
        ),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date-time'),
    ],
)]
class InstalledSensor
{
}