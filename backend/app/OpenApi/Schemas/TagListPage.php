<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * A page of tags with their category and counts (GET /tags/display).
 * Doc-only schema.
 */
#[OAS\Schema(component: 'TagListPage', description: 'A page of tags with category and usage counts.')]
class TagListPage
{
    /** @var array<int, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagListItem')))]
    public array $items = [];
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Tags matching the search, on every page.'))]
    public int $total = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $current_page = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $total_pages = 0;
}
