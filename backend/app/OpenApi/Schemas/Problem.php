<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Every /api error: an RFC 9457 problem details body. Doc-only schema: these
 * classes exist purely to describe response shapes to swagger-php; they are
 * never instantiated.
 */
#[OAS\Schema(
    component: 'Problem',
    description: 'An RFC 9457 problem details body, sent as application/problem+json with every error.',
    required: ['type', 'title', 'status', 'detail'],
)]
class Problem
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'uri', pattern: '^tag:gallery\.mathdad\.me,2026:[A-Z][A-Za-z]+$', description: 'What went wrong: a tag URI ending in a stable PascalCase name (tag:gallery.mathdad.me,2026:MediaNotFound), which a client may branch on. It names the failure and is never fetched.'))]
    public string $type = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'The HTTP status\'s reason phrase (Not Found, Unprocessable Content, …).'))]
    public string $title = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'The HTTP status code, as the response has it.'))]
    public int $status = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Why it failed, in words a person can read; for display, and it may change.'))]
    public string $detail = '';

    /** @var list<mixed> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/ErrorDetail'), description: 'A failed validation\'s broken fields, one entry each; absent otherwise.'))]
    public array $errors = [];
}
