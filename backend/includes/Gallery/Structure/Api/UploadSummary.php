<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * The overall result of an upload request (POST /media). Doc-only schema.
 */
#[OAS\Schema(schema: 'UploadSummary', description: 'Aggregate upload result.')]
class UploadSummary
{
    /** @var array<int, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/UploadResult')))]
    public array $results = [];
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $total_uploaded = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $total_duplicates = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $total_failed = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Total Danbooru tags applied (only when fetch_tags was set).'))]
    public int $total_tags_applied = 0;
}
