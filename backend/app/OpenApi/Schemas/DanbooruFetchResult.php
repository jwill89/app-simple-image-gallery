<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /media/{mediaId}/danbooru-tags. Doc-only schema. */
#[OAS\Schema(
    component: 'DanbooruFetchResult',
    description: 'Outcome of a Danbooru tag import.',
    required: ['tags', 'method', 'tagsApplied', 'tagsCreated'],
    properties: [
        new OAS\Property(property: 'tags', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'), description: 'The media item\'s tags after the import, in display order.')),
        new OAS\Property(property: 'method', schema: new OAS\Schema(type: 'string', enum: ['md5', 'iqdb', 'post'], description: 'How the post was found: by the file\'s MD5, by visual similarity (IQDB), or by the post id given.')),
        new OAS\Property(property: 'tagsApplied', schema: new OAS\Schema(type: 'integer', description: 'Tags applied to the item.')),
        new OAS\Property(property: 'tagsCreated', schema: new OAS\Schema(type: 'integer', description: 'Tags the gallery did not have yet, created for it.')),
    ],
)]
final class DanbooruFetchResult {}
