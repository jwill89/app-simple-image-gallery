<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /media/{mediaId}/lists. Doc-only schema. */
#[OAS\Schema(component: 'ListIdList', description: 'The ids of the account\'s lists that hold a media item.', type: 'array', items: new OAS\Schema\Items(type: 'integer', description: 'A list\'s id.'))]
final class ListIdList {}
