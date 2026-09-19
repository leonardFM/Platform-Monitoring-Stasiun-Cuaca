<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BatchAccepted',
    required: ['accepted', 'message_ids'],
    properties: [
        new OA\Property(property: 'accepted', type: 'integer', example: 2),
        new OA\Property(property: 'message_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    ],
)]
class BatchAccepted
{
}