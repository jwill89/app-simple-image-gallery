<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * A tag enriched with its category name and usage/implication counts, for the
 * tags-management page (GET /tags/display). Doc-only schema.
 */
#[OAS\Schema(schema: 'TagListItem', description: 'A tag with category name and usage counts.')]
class TagListItem
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $tag_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $tag_name = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $category_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public ?string $category_name = null;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Number of media items with this tag.'))]
    public int $media_count = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Number of implications originating from this tag.'))]
    public int $implication_count = 0;
}
