<?php

namespace Routes\Internal;

use PDO;
use Monolog\Logger;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Core\CacheGroup;
use Gallery\Core\DatabaseConnection;
use Gallery\Core\Logger as AppLogger;
use Gallery\Core\ResponseCache;
use Gallery\Repository\TagRepository;

/**
 * AbstractController
 *
 * Base class for the internal API controllers. Provides the shared response
 * envelope and helpers used by every action.
 *
 * Response envelope (all responses are JSON):
 *  - Success — success()/cachedSuccess() write the payload directly: a resource
 *    object, an array of resources, or a bare boolean. HTTP 2xx. Cached GETs
 *    also carry an `X-Cache: HIT|MISS` header.
 *  - Error — error() writes `{ "error": <MachineCode>, "message": <human text> }`
 *    with a 4xx/5xx status. The machine code is a stable PascalCase identifier
 *    (e.g. `MediaNotFound`); the message is for display and may change.
 *
 * Subclasses receive their collaborators via constructor injection (autowired
 * by PHP-DI); they do not pull from a container.
 */
abstract class AbstractController
{
    protected Logger $logger;

    /**
     * AbstractController constructor.
     */
    public function __construct()
    {
        $this->logger = AppLogger::getInstance();
    }

    /**
     * Get a PDO connection instance.
     *
     * @return PDO A PDO connection instance.
     */
    protected function getConnection(): PDO
    {
        return DatabaseConnection::getInstance();
    }

    /**
     * Return a successful JSON response.
     *
     * @param Response $response The response object.
     * @param mixed $data The data to return.
     * @param int $status HTTP status code.
     */
    protected function success(Response $response, mixed $data, int $status = 200): Response
    {
        return $response->withJson($data, $status);
    }

    /**
     * Return a `201 Created` response carrying the newly-created resource.
     *
     * @param Response $response The response object.
     * @param mixed    $data     The created resource to serialize.
     */
    protected function created(Response $response, mixed $data): Response
    {
        return $response->withJson($data, 201);
    }

    /**
     * Return a `204 No Content` response with an empty body (for successful
     * deletes and other mutations that have nothing meaningful to return).
     *
     * @param Response $response The response object.
     */
    protected function noContent(Response $response): Response
    {
        return $response->withStatus(204);
    }

    /**
     * Return an error JSON response with a machine-readable code and human-readable message.
     *
     * @param Response $response The response object.
     * @param string $error The machine-readable error code (e.g. 'MediaNotFound').
     * @param int $status HTTP status code.
     * @param string|null $message Optional human-readable message. If omitted, auto-generated from $error.
     */
    protected function error(Response $response, string $error, int $status, ?string $message = null): Response
    {
        // Convert PascalCase error code to a readable sentence
        $message ??= trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $error));

        return $response->withJson([
            'error' => $error,
            'message' => $message,
        ], $status);
    }

    /**
     * Parse parameters from the request and return the value of the specified parameter.
     *
     * @param array<string, mixed> $parameters The parameters array.
     * @param string $parameter_name The name of the parameter to retrieve.
     * @param mixed $default_value The default value to return if the parameter is not found.
     *
     * @return mixed The value of the specified parameter or the default value.
     */
    protected function parseParameters(array $parameters, string $parameter_name, mixed $default_value): mixed
    {
        return array_key_exists($parameter_name, $parameters) ? $parameters[$parameter_name] : $default_value;
    }

    /**
     * Returns the parsed request body as an associative array.
     *
     * PSR-7 types the parsed body as array|object|null; this normalizes it to a
     * plain array so callers get typed offset access instead of mixed.
     *
     * @return array<string, mixed>
     */
    protected function parsedBody(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /**
     * Reads a request parameter as an int, returning $default when the key is
     * absent. Equivalent to (int) parseParameters(...) but typed: callers no
     * longer cast a mixed value at every use site.
     *
     * @param array<string, mixed> $parameters
     */
    protected function intParam(array $parameters, string $parameter_name, int $default = 0): int
    {
        return array_key_exists($parameter_name, $parameters) ? (int) $parameters[$parameter_name] : $default;
    }

    /**
     * Reads a request parameter as a string, returning $default when the key is
     * absent. A present-but-null value coalesces to an empty string rather than
     * tripping a null-to-string deprecation downstream.
     *
     * @param array<string, mixed> $parameters
     */
    protected function stringParam(array $parameters, string $parameter_name, string $default = ''): string
    {
        return array_key_exists($parameter_name, $parameters) ? (string) ($parameters[$parameter_name] ?? '') : $default;
    }

    /**
     * Resolves an array of tag names to an array of tag IDs in a single query.
     * Invalid/non-existent tags are silently skipped; IDs are de-duplicated.
     *
     * @param string[] $tag_names Array of tag name strings.
     * @param TagRepository $tag_repository The tag repository to look up tags.
     *
     * @return int[] Array of valid tag IDs.
     */
    protected function resolveTagIds(array $tag_names, TagRepository $tag_repository): array
    {
        return $tag_repository->getIdsByNames($tag_names);
    }

    /**
     * Return a cached JSON response, or generate, cache, and return it on miss.
     *
     * The generator callable must return the data to be JSON-encoded.
     * On a cache hit the stored JSON is written directly to the response body,
     * skipping both the DB query and the json_encode step.
     *
     * @param Response   $response  The Slim response object.
     * @param CacheGroup $group     Cache group for targeted invalidation.
     * @param string     $key       Unique cache key within the group.
     * @param int        $ttl       Time-to-live in seconds.
     * @param callable   $generator Callable that returns the response data.
     *
     * @throws \JsonException If the generated data cannot be encoded to JSON.
     */
    protected function cachedSuccess(Response $response, CacheGroup $group, string $key, int $ttl, callable $generator): Response
    {
        $cache = ResponseCache::getInstance();
        $json = $cache->get($group->value, $key);

        if ($json !== null) {
            $response->getBody()->write($json);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Cache', 'HIT');
        }

        $data = $generator();
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $cache->set($group->value, $key, $json, $ttl);

        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Cache', 'MISS');
    }

    /**
     * Invalidate one or more cache groups after a mutation.
     *
     * @param CacheGroup ...$groups Cache groups to invalidate.
     */
    protected function invalidateCache(CacheGroup ...$groups): void
    {
        ResponseCache::getInstance()->invalidateGroups(...array_map(static fn(CacheGroup $g) => $g->value, $groups));
    }
}
