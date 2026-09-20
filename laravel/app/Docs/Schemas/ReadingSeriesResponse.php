<?php

namespace App\Docs\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReadingSeriesResponse',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
            oneOf: [
                new OA\Schema(ref: '#/components/schemas/ReadingSeriesItem'),
                new OA\Schema(ref: '#/components/schemas/ReadingRawItem'),
            ],
        )),
        new OA\Property(
            property: 'meta',
            required: ['interval', 'from', 'to', 'limit', 'offset', 'total'],
            properties: [
                new OA\Property(property: 'interval', type: 'string', enum: ['raw', '1m', '1h', '1d']),
                new OA\Property(property: 'from', type: 'string', format: 'date-time'),
                new OA\Property(property: 'to', type: 'string', format: 'date-time'),
                new OA\Property(property: 'limit', type: 'integer'),
                new OA\Property(property: 'offset', type: 'integer'),
                new OA\Property(property: 'total', type: 'integer'),
            ],
        ),
    ],
)]
class ReadingSeriesResponse
{
}