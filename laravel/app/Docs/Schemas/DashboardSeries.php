<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardSeries',
    required: ['period_start', 'device_name', 'rain_total_mm'],
    properties: [
        new OA\Property(property: 'period_start', type: 'string', format: 'date-time'),
        new OA\Property(property: 'device_name', type: 'string'),
        new OA\Property(property: 'temperature_avg', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'humidity_avg', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'pressure_avg', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'windspeed_avg', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'wind_direction_avg', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'rain_total_mm', type: 'number', format: 'double'),
    ],
)]
class DashboardSeries
{
}