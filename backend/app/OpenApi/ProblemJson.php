<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Spec as OAS;

/**
 * An error response's content: a problem details body
 * (`application/problem+json`, the Problem schema), which every /api error is.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ProblemJson extends OAS\MediaType
{
    public const string MEDIA_TYPE = 'application/problem+json';

    public function __construct()
    {
        parent::__construct(mediaType: self::MEDIA_TYPE, schema: new OAS\Schema(ref: '#/components/schemas/Problem'));
    }
}
