<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * A Danbooru category -> gallery category mapping. Doc-only schema.
 */
#[OAS\Schema(component: 'CategoryMapping', description: 'A Danbooru-to-gallery category mapping.')]
class CategoryMapping
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $danbooru_category_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $danbooru_category_name = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $gallery_category_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true))]
    public ?string $gallery_category_name = null;
}
