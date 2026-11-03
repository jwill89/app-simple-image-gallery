<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * A tag implication row: applying `tag_id` also applies `implied_tag_id`
 * (GET /tag-implications). Doc-only schema.
 */
#[OAS\Schema(component: 'TagImplication', description: 'A tag implication (tag -> implied tag).')]
class TagImplication
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $tag_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $tag_name = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $implied_tag_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $implied_tag_name = '';
}
