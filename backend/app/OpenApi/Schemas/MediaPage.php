<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: A page of GET /media. Doc-only schema. */
#[OAS\Schema(
    component: 'MediaPage',
    description: 'A page of media items, newest first.',
    required: ['items', 'nextCursor'],
    properties: [
        new OAS\Property(property: 'items', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Media'), description: 'The page\'s items.')),
        new OAS\Property(property: 'nextCursor', schema: new OAS\Schema(type: 'string', nullable: true, description: 'Pass as ?cursor= (with the same filters) for the items after these; null when nothing follows.')),
    ],
)]
final class MediaPage {}
