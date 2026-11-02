<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * A simple `{ count }` wrapper (e.g. GET /media/count). Doc-only schema.
 */
#[OAS\Schema(schema: 'Count', description: 'A count wrapper.')]
class Count
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $count = 0;
}
