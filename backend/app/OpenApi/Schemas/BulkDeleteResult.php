<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /media/bulk-delete. Doc-only schema. */
#[OAS\Schema(
    component: 'BulkDeleteResult',
    description: 'Outcome of a bulk media delete.',
    required: ['deleted', 'failed', 'totalDeleted'],
    properties: [
        new OAS\Property(property: 'deleted', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), description: 'Ids deleted.')),
        new OAS\Property(property: 'failed', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), description: 'Ids that could not be deleted (unknown, not a positive number, or failing).')),
        new OAS\Property(property: 'totalDeleted', schema: new OAS\Schema(type: 'integer', description: 'How many were deleted.')),
    ],
)]
final class BulkDeleteResult {}
