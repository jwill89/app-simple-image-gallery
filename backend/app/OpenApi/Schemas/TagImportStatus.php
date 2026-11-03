<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Where a media item's queued Danbooru import stands (GET /media/tag-imports).
 * Doc-only schema.
 */
#[OAS\Schema(component: 'TagImportStatus', description: "A media item's queued Danbooru import.")]
class TagImportStatus
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $media_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', enum: ['queued', 'applied', 'not_found', 'failed', 'none'], description: 'none: no import was queued for it in the last day.'))]
    public string $status = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Tags applied (applied only).'))]
    public int $tags_applied = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true, description: 'Why it failed (failed only): blocked, refused, unreachable, ….'))]
    public ?string $error = null;
}
