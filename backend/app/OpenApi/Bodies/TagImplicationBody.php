<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /tag-implications. Doc-only schema. */
#[OAS\Schema(
    component: 'TagImplicationBody',
    description: 'A new implication: applying the tag applies the implied tag too.',
    required: ['tagId', 'impliedTagId'],
    properties: [
        new OAS\Property(property: 'tagId', schema: new OAS\Schema(type: 'integer', description: 'The implying tag.')),
        new OAS\Property(property: 'impliedTagId', schema: new OAS\Schema(type: 'integer', description: 'The implied tag; it may not lead back to the implying one.')),
    ],
)]
final class TagImplicationBody {}
