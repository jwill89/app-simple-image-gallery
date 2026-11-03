<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * A Danbooru tag name -> gallery tag name mapping. Doc-only schema.
 */
#[OAS\Schema(component: 'TagMapping', description: 'A Danbooru-to-gallery tag name mapping.')]
class TagMapping
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $danbooru_tag = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $gallery_tag = '';
}
