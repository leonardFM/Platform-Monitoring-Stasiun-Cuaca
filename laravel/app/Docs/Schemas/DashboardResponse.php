<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardResponse',
    required: ['devices', 'series', 'recent'],
    properties: [
        new OA\Property(property: 'devices', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardDevice')),
        new OA\Property(property: 'series', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardSeries')),
        new OA\Property(property: 'recent', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardRecent')),
    ],
)]
class DashboardResponse
{
}