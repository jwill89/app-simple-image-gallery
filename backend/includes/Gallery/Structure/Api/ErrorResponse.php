<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * The standard error envelope returned on any 4xx/5xx response.
 *
 * Doc-only schema: these classes exist purely to describe response shapes to
 * swagger-php; they are never instantiated.
 */
#[OAS\Schema(schema: 'ErrorResponse', description: 'Standard error envelope.')]
class ErrorResponse
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Stable PascalCase machine code (e.g. MediaNotFound).'))]
    public string $error = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Human-readable message for display.'))]
    public string $message = '';
}
