<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FavoriteList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin FavoriteList */
#[OAS\Schema(component: 'FavoriteList', description: 'One of an account\'s lists of media.', properties: [
    new OAS\Property(property: 'list_id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'is_default', schema: new OAS\Schema(type: 'boolean', description: 'The list the heart on a card adds to; it can be renamed, not deleted.')),
    new OAS\Property(property: 'media_count', schema: new OAS\Schema(type: 'integer')),
])]
final class FavoriteListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'list_id' => $this->list_id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            // Counted by the controller (withCount / loadCount).
            'media_count' => (int) $this->media_count,
        ];
    }
}
