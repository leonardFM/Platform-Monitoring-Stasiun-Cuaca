<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceStatusResponse',
    required: ['device_id', 'previous_status', 'current_status', 'changed_at'],
    properties: [
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'previous_status', type: 'string', enum: ['provisioned', 'active', 'maintenance', 'decommissioned']),
        new OA\Property(property: 'current_status', type: 'string', enum: ['provisioned', 'active', 'maintenance', 'decommissioned']),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
    ],
)]
class DeviceStatusResponse
{
}