<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Result of a bulk media delete (POST /media/bulk-delete). Doc-only schema.
 */
#[OAS\Schema(component: 'BulkDeleteResult', description: 'Outcome of a bulk media delete.')]
class BulkDeleteResult
{
    /** @var array<int, int> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), description: 'IDs successfully deleted.'))]
    public array $deleted = [];
    /** @var array<int, int> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), description: 'IDs that could not be deleted.'))]
    public array $failed = [];
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $total_deleted = 0;
}
