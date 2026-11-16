<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: One pair of GET /duplicates/report. Doc-only schema. */
#[OAS\Schema(
    component: 'DuplicateMatch',
    description: 'A duplicate media pair with similarity scores.',
    required: ['media1', 'media2', 'distance', 'ssim'],
    properties: [
        new OAS\Property(property: 'media1', schema: new OAS\Schema(description: 'The item with the smaller id.', allOf: [new OAS\Schema(ref: '#/components/schemas/DuplicateMedia')])),
        new OAS\Property(property: 'media2', schema: new OAS\Schema(description: 'The item with the larger id.', allOf: [new OAS\Schema(ref: '#/components/schemas/DuplicateMedia')])),
        new OAS\Property(property: 'distance', schema: new OAS\Schema(type: 'integer', nullable: true, description: 'Hamming distance between their fingerprints.')),
        new OAS\Property(property: 'ssim', schema: new OAS\Schema(type: 'number', format: 'float', nullable: true, description: 'Structural similarity (SSIM) of their thumbnails; null in older reports.')),
    ],
)]
final class DuplicateMatch {}
