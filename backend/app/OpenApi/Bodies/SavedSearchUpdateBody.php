<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /saved-searches/{searchId}. Doc-only schema. */
#[OAS\Schema(
    component: 'SavedSearchUpdateBody',
    description: 'A saved search\'s changes: the fields sent change, the rest stay.',
    properties: [
        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 60, description: 'Unique among the account\'s searches, without regard to case.')),
        new OAS\Property(property: 'query', schema: new OAS\Schema(type: 'string', maxLength: 500, description: 'As the gallery\'s URL writes it: "cat,-dog", or "untagged".')),
    ],
)]
final class SavedSearchUpdateBody {}
