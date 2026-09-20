<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Device',
    required: ['id', 'device_code', 'name', 'location', 'status', 'firmware_version'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'device_code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'location', ref: '#/components/schemas/DeviceLocation'),
        new OA\Property(property: 'status', type: 'string', enum: ['provisioned', 'active', 'maintenance', 'decommissioned']),
        new OA\Property(property: 'firmware_version', type: 'string'),
        new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class Device
{
}