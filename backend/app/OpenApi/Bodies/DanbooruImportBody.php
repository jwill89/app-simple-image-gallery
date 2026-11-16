<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /media/{mediaId}/danbooru-tags. Doc-only schema. */
#[OAS\Schema(
    component: 'DanbooruImportBody',
    description: 'Where to import Danbooru tags from.',
    properties: [
        new OAS\Property(property: 'danbooruPostId', schema: new OAS\Schema(type: 'integer', nullable: true, description: 'A Danbooru post id to import from; left out, the post is found by MD5, then by visual similarity.')),
    ],
)]
final class DanbooruImportBody {}
