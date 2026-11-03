<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * The overall result of an upload request (POST /media). Doc-only schema.
 */
#[OAS\Schema(component: 'UploadSummary', description: 'Aggregate upload result.')]
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
}
