<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /lists/{listId}/ids. Doc-only schema. */
#[OAS\Schema(component: 'MediaIdList', description: 'Every media id in a list.', type: 'array', items: new OAS\Schema\Items(type: 'integer', description: 'A media item\'s id.'))]
final class MediaIdList {}
