<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaleDevice',
    required: ['id', 'device_code', 'name', 'status', 'stale_minutes'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'device_code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'stale_minutes', type: 'integer'),
        new OA\Property(property: 'battery_voltage', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'rssi', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'firmware_version', type: 'string', nullable: true),
    ],
)]
class StaleDevice
{
}