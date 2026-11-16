<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /media/{mediaId}. Doc-only schema. */
#[OAS\Schema(
    component: 'MediaWithTags',
    description: 'A media item; with include=tags, also its tags in display order.',
    allOf: [
        new OAS\Schema(ref: '#/components/schemas/Media'),
        new OAS\Schema(properties: [
            new OAS\Property(property: 'tags', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'), description: 'Its tags by category sort order, then name (include=tags only).')),
        ]),
    ],
)]
final class MediaWithTags {}
