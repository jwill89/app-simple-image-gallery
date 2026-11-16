<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /danbooru/tag-mappings/{mappingId}. Doc-only schema. */
#[OAS\Schema(
    component: 'TagMappingUpdateBody',
    description: 'A mapping\'s changes: the fields sent change, the rest stay.',
    properties: [
        new OAS\Property(property: 'danbooruTag', schema: new OAS\Schema(type: 'string', maxLength: 200, description: 'The tag as Danbooru names it; one mapping each.')),
        new OAS\Property(property: 'galleryTag', schema: new OAS\Schema(type: 'string', maxLength: 200, description: 'The gallery tag name it imports as.')),
    ],
)]
final class TagMappingUpdateBody {}
