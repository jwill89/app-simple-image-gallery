<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * A single duplicate-pair match, with both media items summarized and the
 * distance/SSIM similarity scores. Doc-only schema.
 */
#[OAS\Schema(schema: 'DuplicateMatch', description: 'A duplicate media pair with similarity scores.')]
class DuplicateMatch
{
    /** @var array<string, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'object', properties: [
        new OAS\Property(property: 'media_id', schema: new OAS\Schema(type: 'integer')),
        new OAS\Property(property: 'file_name', schema: new OAS\Schema(type: 'string')),
        new OAS\Property(property: 'hash', schema: new OAS\Schema(type: 'string')),
    ]))]
    public array $media_1 = [];
    /** @var array<string, mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'object', properties: [
        new OAS\Property(property: 'media_id', schema: new OAS\Schema(type: 'integer')),
        new OAS\Property(property: 'file_name', schema: new OAS\Schema(type: 'string')),
        new OAS\Property(property: 'hash', schema: new OAS\Schema(type: 'string')),
    ]))]
    public array $media_2 = [];
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true, description: 'Hamming distance between fingerprints.'))]
    public ?int $distance = null;
    #[OAS\Property(schema: new OAS\Schema(type: 'number', format: 'float', nullable: true, description: 'Structural similarity (SSIM) score.'))]
    public ?float $ssim = null;
}
