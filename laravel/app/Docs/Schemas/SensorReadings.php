<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SensorReadings',
    required: ['temperature_c', 'humidity_pct', 'pressure_hpa', 'windspeed_ms', 'wind_direction_deg', 'rain_counter'],
    properties: [
        new OA\Property(property: 'temperature_c', type: 'number', format: 'float', example: 26.5, description: 'Air temperature in Celsius'),
        new OA\Property(property: 'humidity_pct', type: 'number', format: 'float', example: 62.0, description: 'Relative humidity as a percentage (0-100)'),
        new OA\Property(property: 'pressure_hpa', type: 'number', format: 'float', example: 1015.2, description: 'Atmospheric pressure in hectopascals (hPa)'),
        new OA\Property(property: 'windspeed_ms', type: 'number', format: 'float', example: 4.1, description: 'Wind speed in metres per second'),
        new OA\Property(property: 'wind_direction_deg', type: 'number', format: 'float', example: 180.0, description: 'Wind direction in degrees (0-360, meteorological)'),
        new OA\Property(property: 'rain_counter', type: 'integer', format: 'int64', example: 1000, description: 'Cumulative raw rain counter as reported by the rain gauge'),
    ],
)]
class SensorReadings
{
}