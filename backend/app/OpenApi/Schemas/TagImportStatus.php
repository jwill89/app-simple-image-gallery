<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /media/tag-imports. Doc-only schema. */
#[OAS\Schema(
    component: 'TagImportStatus',
    description: 'A media item\'s queued Danbooru import.',
    required: ['mediaId', 'status', 'tagsApplied', 'error'],
    properties: [
        new OAS\Property(property: 'mediaId', schema: new OAS\Schema(type: 'integer', description: 'The item asked about.')),
        new OAS\Property(property: 'status', schema: new OAS\Schema(type: 'string', enum: ['queued', 'applied', 'not_found', 'failed', 'none'], description: 'Where it stands; none: no import was queued for it in the last day.')),
        new OAS\Property(property: 'tagsApplied', schema: new OAS\Schema(type: 'integer', description: 'Tags applied (applied only; otherwise 0).')),
        new OAS\Property(property: 'error', schema: new OAS\Schema(type: 'string', nullable: true, description: 'Why it failed (failed only): blocked, refused, unreachable, ….')),
    ],
)]
final class TagImportStatus {}
