<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Result of importing Danbooru tags for a media item
 * (POST /media/{media_id}/danbooru-tags). Doc-only schema.
 */
#[OAS\Schema(component: 'DanbooruFetchResult', description: 'Outcome of a Danbooru tag import.')]
class DanbooruFetchResult
{
    /** @var array<int, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'), description: "The media item's tags after import."))]
    public array $tags = [];

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Lookup method used (md5 / iqdb / post).'))]
    public string $method = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $tags_applied = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $tags_created = 0;
}
