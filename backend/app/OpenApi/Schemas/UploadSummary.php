<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /media. Doc-only schema. */
#[OAS\Schema(
    component: 'UploadSummary',
    description: 'Every uploaded file\'s outcome, with totals.',
    required: ['results', 'totalUploaded', 'totalDuplicates', 'totalFailed'],
    properties: [
        new OAS\Property(property: 'results', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/UploadResult'), description: 'One entry per file, in the order sent.')),
        new OAS\Property(property: 'totalUploaded', schema: new OAS\Schema(type: 'integer', description: 'Files added.')),
        new OAS\Property(property: 'totalDuplicates', schema: new OAS\Schema(type: 'integer', description: 'Files skipped as copies of items there already.')),
        new OAS\Property(property: 'totalFailed', schema: new OAS\Schema(type: 'integer', description: 'Files refused.')),
    ],
)]
final class UploadSummary {}
