<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardRecent',
    required: ['taken_at', 'device_name'],
    properties: [
        new OA\Property(property: 'taken_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'device_name', type: 'string'),
        new OA\Property(property: 'temperature_c', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'humidity_pct', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'pressure_hpa', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'windspeed_ms', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'wind_direction_deg', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'rain_counter', type: 'integer', format: 'int64', nullable: true),
        new OA\Property(property: 'rain_delta_mm', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'quality_score', type: 'integer', nullable: true),
        new OA\Property(property: 'quality_flags', type: 'array', items: new OA\Items(type: 'string')),
    ],
)]
class DashboardRecent
{
}