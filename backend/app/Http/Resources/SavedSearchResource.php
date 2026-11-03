<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SavedSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin SavedSearch */
#[OAS\Schema(component: 'SavedSearch', description: 'A tag search an account keeps by name.', properties: [
    new OAS\Property(property: 'search_id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'query', schema: new OAS\Schema(type: 'string', description: 'As the gallery\'s URL writes it: "cat,-dog", or "untagged".')),
])]
final class SavedSearchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'search_id' => $this->search_id,
            'name' => $this->name,
            'query' => $this->query,
        ];
    }
}
