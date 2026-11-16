<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /media/bulk-delete. Doc-only schema. */
#[OAS\Schema(
    component: 'BulkDeleteBody',
    description: 'Media items to delete, by id.',
    required: ['mediaIds'],
    properties: [
        new OAS\Property(property: 'mediaIds', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), minItems: 1, description: 'The ids to delete.')),
    ],
)]
final class BulkDeleteBody {}
