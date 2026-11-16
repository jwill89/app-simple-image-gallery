<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /duplicates/report. Doc-only schema. */
#[OAS\Schema(
    component: 'DuplicateReport',
    description: 'The latest duplicate-detection report.',
    required: ['reportFile', 'generatedAt', 'imagesCompared', 'duplicatesFound', 'matches'],
    properties: [
        new OAS\Property(property: 'reportFile', schema: new OAS\Schema(type: 'string', description: 'The report\'s file name (dupes-YYYY-MM-DD.json).')),
        new OAS\Property(property: 'generatedAt', schema: new OAS\Schema(type: 'string', nullable: true, description: 'When the scan ran, as the report writes it.')),
        new OAS\Property(property: 'imagesCompared', schema: new OAS\Schema(type: 'integer', nullable: true, description: 'How many images the scan compared.')),
        new OAS\Property(property: 'duplicatesFound', schema: new OAS\Schema(type: 'integer', description: 'How many pairs are listed.')),
        new OAS\Property(property: 'matches', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DuplicateMatch'), description: 'The pairs not dismissed since, whose items both still exist.')),
    ],
)]
final class DuplicateReport {}
