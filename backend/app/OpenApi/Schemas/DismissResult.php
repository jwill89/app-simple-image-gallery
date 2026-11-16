<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /duplicates/dismissals. Doc-only schema. */
#[OAS\Schema(
    component: 'DismissResult',
    description: 'A dismissed duplicate pair.',
    required: ['dismissed', 'mediaId1', 'mediaId2'],
    properties: [
        new OAS\Property(property: 'dismissed', schema: new OAS\Schema(type: 'boolean', description: 'Always true.')),
        new OAS\Property(property: 'mediaId1', schema: new OAS\Schema(type: 'integer', description: 'The pair\'s smaller id.')),
        new OAS\Property(property: 'mediaId2', schema: new OAS\Schema(type: 'integer', description: 'The pair\'s larger id.')),
    ],
)]
final class DismissResult {}
