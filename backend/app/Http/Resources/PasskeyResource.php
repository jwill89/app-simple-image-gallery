<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passkeys\Passkey;
use OpenApi\Spec as OAS;

/** @mixin Passkey */
#[OAS\Schema(component: 'Passkey', description: 'A passkey an account signs in with.', properties: [
    new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'authenticator', schema: new OAS\Schema(type: 'string', nullable: true, description: 'Where it is kept (iCloud Keychain, Google Password Manager, Windows Hello…), when its maker says.')),
    new OAS\Property(property: 'created_at', schema: new OAS\Schema(type: 'string', format: 'date-time')),
    new OAS\Property(property: 'last_used_at', schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true)),
])]
final class PasskeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'authenticator' => $this->authenticator,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
