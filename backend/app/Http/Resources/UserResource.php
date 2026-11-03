<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin User */
#[OAS\Schema(component: 'User', description: 'An account.', properties: [
    new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'username', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'is_admin', schema: new OAS\Schema(type: 'boolean')),
    new OAS\Property(property: 'created_at', schema: new OAS\Schema(type: 'string', format: 'date-time')),
])]
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'is_admin' => $this->is_admin,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
