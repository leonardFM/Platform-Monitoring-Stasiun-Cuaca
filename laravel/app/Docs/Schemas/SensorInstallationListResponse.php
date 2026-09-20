<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SensorInstallationListResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
            oneOf: [
                new OA\Schema(ref: '#/components/schemas/SensorInstallation'),
                new OA\Schema(ref: '#/components/schemas/InstalledSensor'),
            ],
        )),
    ],
)]
class SensorInstallationListResponse
{
}