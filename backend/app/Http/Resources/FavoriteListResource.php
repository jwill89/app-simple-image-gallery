<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FavoriteList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin FavoriteList */
#[OAS\Schema(
    component: 'FavoriteList',
    description: 'One of an account\'s lists of media.',
    required: ['listId', 'name', 'isDefault', 'mediaCount'],
    properties: [
        new OAS\Property(property: 'listId', schema: new OAS\Schema(type: 'integer', description: 'The list\'s id.')),
        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', description: 'Unique among the account\'s lists, without regard to case.')),
        new OAS\Property(property: 'isDefault', schema: new OAS\Schema(type: 'boolean', description: 'The list the heart on a card adds to; it can be renamed, not deleted.')),
        new OAS\Property(property: 'mediaCount', schema: new OAS\Schema(type: 'integer', description: 'How many media items it holds.')),
    ],
)]
final class FavoriteListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'listId' => $this->list_id,
            'name' => $this->name,
            'isDefault' => (bool) $this->is_default,
            // Counted by the controller (withCount / loadCount).
            'mediaCount' => (int) $this->media_count,
        ];
    }
}
