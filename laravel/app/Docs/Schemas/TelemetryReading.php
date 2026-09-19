<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TelemetryReading',
    required: ['message_id', 'taken_at', 'sensors'],
    properties: [
        new OA\Property(property: 'message_id', type: 'string', format: 'uuid', example: '5b1b1a3e-3c2d-4e5f-9a8b-1c2d3e4f5a6b', description: 'Client generated idempotency key; deduplicated by (device_id, message_id)'),
        new OA\Property(property: 'taken_at', type: 'string', format: 'date-time', example: '2026-09-19T01:05:00Z', description: 'Timestamp of when the reading was sampled by the device (UTC)'),
        new OA\Property(property: 'sensors', ref: '#/components/schemas/SensorReadings'),
    ],
)]
class TelemetryReading
{
}