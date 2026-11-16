<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /tags/{tagId}/migrate. Doc-only schema. */
#[OAS\Schema(
    component: 'MigrateResult',
    description: 'Outcome of a tag migration.',
    required: ['migrated', 'sourceTagId', 'targetTagId'],
    properties: [
        new OAS\Property(property: 'migrated', schema: new OAS\Schema(type: 'boolean', description: 'Always true.')),
        new OAS\Property(property: 'sourceTagId', schema: new OAS\Schema(type: 'integer', description: 'The tag whose media moved; it is kept, on no media.')),
        new OAS\Property(property: 'targetTagId', schema: new OAS\Schema(type: 'integer', description: 'The tag that received the media.')),
    ],
)]
final class MigrateResult {}
