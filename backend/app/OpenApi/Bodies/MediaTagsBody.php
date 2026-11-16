<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /media/{mediaId}/tags. Doc-only schema. */
#[OAS\Schema(
    component: 'MediaTagsBody',
    description: 'Tags to apply to a media item, with what they imply.',
    required: ['tagIds'],
    properties: [
        new OAS\Property(property: 'tagIds', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), minItems: 1, description: 'The tags\' ids; every one must exist.')),
    ],
)]
final class MediaTagsBody {}
