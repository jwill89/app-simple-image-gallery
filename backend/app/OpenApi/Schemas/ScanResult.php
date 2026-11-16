<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /duplicates/scan. Doc-only schema. */
#[OAS\Schema(
    component: 'ScanResult',
    description: 'Duplicate-scan summary statistics.',
    required: ['success', 'message', 'imagesCompared', 'lshCandidates', 'duplicatesFound', 'executionTime'],
    properties: [
        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean', description: 'Always true.')),
        new OAS\Property(property: 'message', schema: new OAS\Schema(type: 'string', description: 'A line to show.')),
        new OAS\Property(property: 'imagesCompared', schema: new OAS\Schema(type: 'integer', description: 'How many images had fingerprints to compare.')),
        new OAS\Property(property: 'lshCandidates', schema: new OAS\Schema(type: 'integer', description: 'Pairs that shared a hash band, before the distance check.')),
        new OAS\Property(property: 'duplicatesFound', schema: new OAS\Schema(type: 'integer', description: 'Pairs reported.')),
        new OAS\Property(property: 'executionTime', schema: new OAS\Schema(type: 'number', format: 'float', description: 'How long the scan took, in seconds.')),
    ],
)]
final class ScanResult {}
