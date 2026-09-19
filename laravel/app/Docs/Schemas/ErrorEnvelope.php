<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ErrorEnvelope',
    required: ['error'],
    properties: [
        new OA\Property(
            property: 'error',
            required: ['code', 'message'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'bad_request'),
                new OA\Property(property: 'message', type: 'string', example: 'temperature_c out of bounds [-100, 100]'),
            ],
        ),
    ],
)]
class ErrorEnvelope
{
}