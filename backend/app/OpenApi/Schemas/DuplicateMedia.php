<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: One side of a DuplicateMatch. Doc-only schema. */
#[OAS\Schema(
    component: 'DuplicateMedia',
    description: 'A media item in a duplicate pair.',
    required: ['mediaId', 'fileName', 'hash'],
    properties: [
        new OAS\Property(property: 'mediaId', schema: new OAS\Schema(type: 'integer', description: 'The item\'s id.')),
        new OAS\Property(property: 'fileName', schema: new OAS\Schema(type: 'string', description: 'Its file name.')),
        new OAS\Property(property: 'hash', schema: new OAS\Schema(type: 'string', description: 'MD5 hash of its file.')),
    ],
)]
final class DuplicateMedia {}
