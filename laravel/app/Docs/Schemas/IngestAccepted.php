<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'IngestAccepted',
    required: ['accepted', 'message_id'],
    properties: [
        new OA\Property(property: 'accepted', type: 'boolean', example: true),
        new OA\Property(property: 'message_id', type: 'string', format: 'uuid', example: '5b1b1a3e-3c2d-4e5f-9a8b-1c2d3e4f5a6b'),
    ],
)]
class IngestAccepted
{
}