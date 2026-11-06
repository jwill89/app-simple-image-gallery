<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Response for GET /auth/session: the account this browser is signed in as,
 * or null. Doc-only schema.
 */
#[OAS\Schema(component: 'SessionResponse', description: 'Who this browser is signed in as.', required: ['user'])]
class SessionResponse
{
    #[OAS\Property(schema: new OAS\Schema(oneOf: [new OAS\Schema(ref: '#/components/schemas/User'), new OAS\Schema(type: 'null')], description: 'Null when signed out.'))]
    public ?object $user = null;
}
