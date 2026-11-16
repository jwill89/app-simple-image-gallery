<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: One file of POST /media. Doc-only schema. */
#[OAS\Schema(
    component: 'UploadResult',
    description: 'One uploaded file\'s outcome.',
    required: ['fileName', 'status'],
    properties: [
        new OAS\Property(property: 'fileName', schema: new OAS\Schema(type: 'string', description: 'The file as uploaded, or as saved once it is.')),
        new OAS\Property(property: 'status', schema: new OAS\Schema(type: 'string', enum: ['success', 'duplicate', 'error'], description: 'What happened to it.')),
        new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'integer', description: 'The new media item\'s id (success only).')),
        new OAS\Property(property: 'existingId', schema: new OAS\Schema(type: 'integer', description: 'The item it duplicates (duplicate only).')),
        new OAS\Property(property: 'hash', schema: new OAS\Schema(type: 'string', description: 'MD5 hash of the file (success and duplicate).')),
        new OAS\Property(property: 'message', schema: new OAS\Schema(type: 'string', description: 'Why it failed (error only).')),
        new OAS\Property(property: 'tagsStatus', schema: new OAS\Schema(type: 'string', enum: ['queued'], description: 'With fetchTags: the Danbooru lookup is queued; GET /media/tag-imports says how it went.')),
        new OAS\Property(property: 'tagsError', schema: new OAS\Schema(type: 'string', description: 'With fetchTags: why no Danbooru lookup was queued (Danbooru is not set up on the server). The file is still uploaded.')),
    ],
)]
final class UploadResult {}
