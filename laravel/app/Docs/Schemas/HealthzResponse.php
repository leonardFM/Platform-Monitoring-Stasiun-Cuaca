<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HealthzResponse',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', example: 'ok', description: 'Service health: ok or degraded'),
    ],
)]
class HealthzResponse
{
}