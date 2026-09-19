<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Weather Station Monitoring Platform API',
    description: 'Ingest telemetry from weather stations and query aggregated dashboard data. Devices authenticate with an `X-API-Key` header; see the demo key pre-filled on the ingest operations.',
)]
#[OA\SecurityScheme(
    securityScheme: 'api_key',
    type: 'apiKey',
    in: 'header',
    name: 'X-API-Key',
    description: 'Device API key',
)]
class OpenApi
{
}