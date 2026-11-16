<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Response body: POST /auth/passkey/options and /account/passkeys/options.
 * The WebAuthn options the browser passes on as they are, so their members
 * keep WebAuthn's names. Doc-only schema.
 */
#[OAS\Schema(
    component: 'PasskeyOptions',
    description: 'WebAuthn options for the browser (PublicKeyCredentialRequestOptionsJSON to sign in, PublicKeyCredentialCreationOptionsJSON to add a passkey), with a challenge this session answers once.',
    type: 'object',
    additionalProperties: true,
    required: ['challenge'],
    properties: [
        new OAS\Property(property: 'challenge', schema: new OAS\Schema(type: 'string', description: 'Random, base64url.')),
    ],
)]
final class PasskeyOptions {}
