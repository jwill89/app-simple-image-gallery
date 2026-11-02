<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * The latest duplicate-scan report (GET /duplicates/report), with dismissed
 * pairs filtered out. Doc-only schema.
 */
#[OAS\Schema(schema: 'DuplicateReport', description: 'The latest duplicate-detection report.')]
class DuplicateReport
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $report_file = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', nullable: true, description: 'ISO timestamp the report was generated.'))]
    public ?string $generated_at = null;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true))]
    public ?int $images_compared = null;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $duplicates_found = 0;
    /** @var array<int, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DuplicateMatch')))]
    public array $matches = [];
}
