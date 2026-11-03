<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Summary stats from a duplicate scan (POST /duplicates/scan). Doc-only schema.
 */
#[OAS\Schema(component: 'ScanResult', description: 'Duplicate-scan summary statistics.')]
class ScanResult
{
    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public bool $success = true;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $message = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $images_compared = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $lsh_candidates = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public int $duplicates_found = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'number', format: 'float', description: 'Execution time in seconds.'))]
    public float $execution_time = 0.0;
}
