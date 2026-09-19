<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BatchRequest',
    required: ['readings'],
    properties: [
        new OA\Property(property: 'readings', type: 'array', items: new OA\Items(ref: '#/components/schemas/TelemetryReading')),
    ],
)]
class BatchRequest
{
}