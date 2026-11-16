<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin User */
#[OAS\Schema(
    component: 'User',
    description: 'An account.',
    required: ['id', 'username', 'isAdmin', 'createdAt'],
    properties: [
        new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'integer', description: 'The account\'s id.')),
        new OAS\Property(property: 'username', schema: new OAS\Schema(type: 'string', description: 'What it signs in with; unique without regard to case.')),
        new OAS\Property(property: 'isAdmin', schema: new OAS\Schema(type: 'boolean', description: 'Whether it may upload, delete and manage tags and accounts.')),
        new OAS\Property(property: 'createdAt', schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, description: 'When it was made, RFC 3339 in UTC.')),
    ],
)]
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'isAdmin' => $this->is_admin,
            'createdAt' => $this->created_at !== null ? Timestamp::of($this->created_at) : null,
        ];
    }
}
