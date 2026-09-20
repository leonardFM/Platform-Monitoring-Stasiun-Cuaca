<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaleDevicesResponse',
    required: ['threshold_minutes', 'count', 'data'],
    properties: [
        new OA\Property(property: 'threshold_minutes', type: 'integer'),
        new OA\Property(property: 'count', type: 'integer'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/StaleDevice')
        ),
    ],
)]
class StaleDevicesResponse
{
}