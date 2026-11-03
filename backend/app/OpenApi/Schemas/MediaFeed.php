<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Media after a cursor (GET /media): the next items of a listing, unaffected
 * by items added or removed since the cursor was issued. Doc-only schema.
 */
#[OAS\Schema(component: 'MediaFeed', description: 'Media items after a cursor.')]
class MediaFeed
{
    /** @var array<int, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Media')))]
    public array $items = [];
    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true, description: 'Pass as ?cursor= for the items after these; null when nothing follows.'))]
    public ?string $next_cursor = null;
}
