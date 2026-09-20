<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SensorInstallation',
    required: ['id', 'sensor_id', 'device_id', 'installed_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sensor_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'removed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class SensorInstallation
{
}