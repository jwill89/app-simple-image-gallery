<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /duplicates/dismissals. Doc-only schema. */
#[OAS\Schema(
    component: 'DismissalBody',
    description: 'A pair of media items that aren\'t duplicates.',
    required: ['mediaId1', 'mediaId2'],
    properties: [
        new OAS\Property(property: 'mediaId1', schema: new OAS\Schema(type: 'integer', description: 'One item.')),
        new OAS\Property(property: 'mediaId2', schema: new OAS\Schema(type: 'integer', description: 'The other, a different item.')),
    ],
)]
final class DismissalBody {}
