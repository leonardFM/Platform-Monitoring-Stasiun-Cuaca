<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Sensor',
    required: ['id', 'serial_number', 'sensor_type', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
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
            nullable: true,
        ),
        new OA\Property(property: 'manufacturer', type: 'string', nullable: true),
        new OA\Property(property: 'model', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'maintenance', 'retired']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class Sensor
{
}