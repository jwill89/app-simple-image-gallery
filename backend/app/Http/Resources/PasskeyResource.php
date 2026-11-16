<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Passkey;
use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin Passkey */
#[OAS\Schema(
    component: 'Passkey',
    description: 'A passkey an account signs in with.',
    required: ['id', 'name', 'authenticator', 'createdAt', 'lastUsedAt'],
    properties: [
        new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'integer', description: 'The passkey\'s id.')),
        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', description: 'What the account calls it.')),
        new OAS\Property(property: 'authenticator', schema: new OAS\Schema(type: 'string', nullable: true, description: 'Where it is kept (iCloud Keychain, Google Password Manager, Windows Hello…), when its maker says.')),
        new OAS\Property(property: 'createdAt', schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, description: 'When it was added, RFC 3339 in UTC.')),
        new OAS\Property(property: 'lastUsedAt', schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, description: 'When it last signed in, RFC 3339 in UTC; null if it never has.')),
    ],
)]
final class PasskeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'authenticator' => $this->authenticator,
            'createdAt' => $this->created_at !== null ? Timestamp::of($this->created_at) : null,
            'lastUsedAt' => $this->last_used_at !== null ? Timestamp::of($this->last_used_at) : null,
        ];
    }
}
