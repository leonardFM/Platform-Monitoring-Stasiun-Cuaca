<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceCredential',
    required: ['id', 'device_id', 'api_key', 'secret', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'device_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'api_key', type: 'string'),
        new OA\Property(property: 'secret', type: 'string', description: 'Plain secret - only returned once on creation'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class DeviceCredential
{
}