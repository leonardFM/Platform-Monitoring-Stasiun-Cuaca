<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardDevice',
    required: ['id', 'name', 'location', 'rain_today_mm', 'latest'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'location', type: 'object'),
        new OA\Property(property: 'rain_today_mm', type: 'number', format: 'double'),
        new OA\Property(property: 'latest', ref: '#/components/schemas/DashboardLatest', nullable: true),
    ],
)]
class DashboardDevice
{
}