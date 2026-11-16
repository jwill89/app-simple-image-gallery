<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET and POST /tag-implications. Doc-only schema. */
#[OAS\Schema(
    component: 'TagImplication',
    description: 'A tag implication: applying the tag applies the implied tag too.',
    required: ['tagId', 'tagName', 'impliedTagId', 'impliedTagName'],
    properties: [
        new OAS\Property(property: 'tagId', schema: new OAS\Schema(type: 'integer', description: 'The implying tag.')),
        new OAS\Property(property: 'tagName', schema: new OAS\Schema(type: 'string', description: 'Its name.')),
        new OAS\Property(property: 'impliedTagId', schema: new OAS\Schema(type: 'integer', description: 'The implied tag.')),
        new OAS\Property(property: 'impliedTagName', schema: new OAS\Schema(type: 'string', description: 'Its name.')),
    ],
)]
final class TagImplication {}
