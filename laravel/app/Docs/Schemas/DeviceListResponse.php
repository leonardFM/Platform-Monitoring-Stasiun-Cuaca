<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceListResponse',
    required: ['data', 'pagination'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Device')),
        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
    ],
)]
class DeviceListResponse
{
}