<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceHealth',
    required: ['device_id'],
    properties: [
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'battery_voltage', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'rssi', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'firmware_version', type: 'string', nullable: true),
        new OA\Property(property: 'last_heartbeat_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'uptime_seconds', type: 'integer', nullable: true),
        new OA\Property(property: 'is_stale', type: 'boolean'),
        new OA\Property(property: 'stale_minutes', type: 'integer', nullable: true),
    ],
)]
class DeviceHealth
{
}