<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** One broken field of a failed validation (Problem's `errors`). Doc-only schema. */
#[OAS\Schema(component: 'ErrorDetail', description: 'One field a validation refused.', required: ['message', 'location'])]
class ErrorDetail
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'What is wrong with the field, in words a person can read.'))]
    public string $message = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Where the field is: body.tagName, query.limit, path.mediaId.'))]
    public string $location = '';
}
