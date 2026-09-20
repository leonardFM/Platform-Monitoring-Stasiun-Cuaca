<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeviceLocation',
    required: ['id', 'name', 'latitude', 'longitude'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'latitude', type: 'number', format: 'double'),
        new OA\Property(property: 'longitude', type: 'number', format: 'double'),
        new OA\Property(property: 'altitude', type: 'number', format: 'double', nullable: true),
    ],
)]
class DeviceLocation
{
}