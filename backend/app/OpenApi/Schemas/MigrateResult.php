<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Result of migrating one tag into another (POST /tags/{tag_id}/migrate).
 * Doc-only schema.
 */
#[OAS\Schema(component: 'MigrateResult', description: 'Outcome of a tag migration.')]
class MigrateResult
{
    #[OAS\Property(schema: new OAS\Schema(type: 'boolean'))]
    public bool $migrated = true;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'The source tag (now deleted).'))]
    public int $source_tag_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'The target tag that received the media.'))]
    public int $target_tag_id = 0;
}
