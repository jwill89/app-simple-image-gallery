<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * What POST /auth/passkey/options and POST /account/passkeys/options answer:
 * WebAuthn's options, as the browser's PublicKeyCredential.parseRequestOptionsFromJSON()
 * and parseCreationOptionsFromJSON() read them. Doc-only schema.
 */
#[OAS\Schema(
    component: 'PasskeyOptions',
    description: 'WebAuthn options for the browser (PublicKeyCredentialRequestOptionsJSON to sign in, PublicKeyCredentialCreationOptionsJSON to add a passkey), with a challenge this session answers once.',
    type: 'object',
    additionalProperties: true,
    required: ['challenge'],
)]
class PasskeyOptions
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Random, base64url.'))]
    public string $challenge = '';
}
