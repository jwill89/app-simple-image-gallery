<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * A paginated page of media items (GET /media). Doc-only schema.
 */
#[OAS\Schema(schema: 'MediaPage', description: 'A paginated page of media items.')]
class MediaPage
{
    /** @var array<int, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Media')))]
    public array $items = [];
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $total_pages = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $current_page = 0;
}
