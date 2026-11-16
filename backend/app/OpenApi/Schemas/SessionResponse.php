<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /auth/session. Doc-only schema. */
#[OAS\Schema(component: 'SessionResponse', description: 'Who this browser is signed in as.', required: ['user'], properties: [
    new OAS\Property(property: 'user', schema: new OAS\Schema(oneOf: [new OAS\Schema(ref: '#/components/schemas/User'), new OAS\Schema(type: 'null')], description: 'The account; null when signed out.')),
])]
final class SessionResponse {}
