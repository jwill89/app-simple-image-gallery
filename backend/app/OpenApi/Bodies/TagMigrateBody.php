<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /tags/{tagId}/migrate. Doc-only schema. */
#[OAS\Schema(
    component: 'TagMigrateBody',
    description: 'Where to move a tag\'s media.',
    required: ['targetTagId'],
    properties: [
        new OAS\Property(property: 'targetTagId', schema: new OAS\Schema(type: 'integer', description: 'The tag that receives the media.')),
    ],
)]
final class TagMigrateBody {}
