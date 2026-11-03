<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Result of dismissing a duplicate pair (POST /duplicates/dismissals).
 * Doc-only schema.
 */
#[OAS\Schema(component: 'DismissResult', description: 'A dismissed duplicate pair.')]
class DismissResult
{
    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public bool $dismissed = true;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $media_id_1 = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $media_id_2 = 0;
}
