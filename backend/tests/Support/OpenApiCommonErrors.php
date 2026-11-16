<?php

declare(strict_types=1);

namespace Tests\Support;

use App\OpenApi\ProblemJson;
use OpenApi\Augmenter\Group;
use OpenApi\Spec as OAS;
use OpenApi\Specification;
use OpenApi\Utils\PipeInterface;

/**
 * Declares on each operation the errors that every /api route of its kind can
 * answer, so no operation leaves them out:
 *
 * - 429 RateLimitExceeded, with Retry-After, on every operation (the API's
 *   limit counts every request);
 * - 400 MalformedJson on every operation with a JSON request body;
 * - 401 Unauthorized on every operation that needs the session cookie;
 * - 403 ForbiddenOrigin on every write (VerifyOrigin, PreventRequestForgery).
 *
 * Where an operation declares that status already, the code is added to its
 * description.
 *
 * @implements PipeInterface<Specification>
 */
final class OpenApiCommonErrors implements PipeInterface
{
    private const array WRITES = ['post', 'put', 'patch', 'delete'];

    public function __invoke(mixed $payload): mixed
    {
        foreach ($payload->operations as $operation) {
            if ($operation->requestBody?->content !== null && self::hasJsonBody($operation->requestBody)) {
                self::add($operation, 400, 'MalformedJson');
            }
            if ($operation->security !== null && $operation->security !== []) {
                self::add($operation, 401, 'Unauthorized');
            }
            if (in_array($operation->method, self::WRITES, true)) {
                self::add($operation, 403, 'ForbiddenOrigin');
            }
            self::add($operation, 429, 'RateLimitExceeded', [
                new OAS\Header(header: 'Retry-After', description: 'Seconds until a request is counted again.', schema: new OAS\Schema(type: 'integer')),
            ]);
        }

        return null;
    }

    public function group(): \BackedEnum
    {
        return Group::Augment;
    }

    private static function hasJsonBody(OAS\RequestBody $body): bool
    {
        return array_any($body->content ?? [], fn($content) => $content instanceof OAS\MediaType && $content->mediaType === 'application/json');
    }

    /** @param list<OAS\Header> $headers */
    private static function add(OAS\Operation $operation, int $status, string $code, array $headers = []): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ((int) $response->response === $status) {
                if (!str_contains((string) $response->description, $code)) {
                    $response->description .= ", {$code}";
                }

                return;
            }
        }

        $responses = $operation->responses ?? [];
        $responses[] = new OAS\Response(response: $status, description: $code, headers: $headers === [] ? null : $headers, content: [new ProblemJson()]);
        usort($responses, static fn(OAS\Response $a, OAS\Response $b): int => (int) $a->response <=> (int) $b->response);
        $operation->responses = $responses;
    }
}
