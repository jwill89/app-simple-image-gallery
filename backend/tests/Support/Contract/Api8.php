<?php

declare(strict_types=1);

namespace Tests\Support\Contract;

use App\Exceptions\ApiException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API 4 recordings, translated into what API 8 is asked and answers, by
 * the changes API 8 made to every request and response alike (the recordings'
 * README lists them):
 *
 * - JSON fields, form fields and query parameters are camelCase;
 * - the listings in the path (/media/page/{page}/{perPage},
 *   /media/untagged/…, /media/with-tags/{tags}/…) are GET /media with
 *   `limit`, `offset` and `tags` or `untagged=true`, their total in
 *   X-Total-Count rather than `total_pages` and `current_page`;
 * - PUT on a tag, a tag category or a Danbooru tag mapping is PATCH;
 * - an error is a problem details body (application/problem+json), with a
 *   failed validation at 422, a duplicate or a conflict at 409 and a body's
 *   reference to nothing at 422, and the rate limiter's `retry_after` is left
 *   to Retry-After;
 * - a media item's `file_time` is RFC 3339 in UTC;
 * - GET /tags sends its size in X-Total-Count.
 *
 * A step these don't cover is a divergence (tests/Feature/Contract/
 * divergences.php), written in API 8's terms.
 */
final class Api8
{
    /** The new status of an error code whose status changed, by code or by "code@route". */
    private const array STATUS = [
        'CannotImplySelf' => 422,
        'CannotMigrateToSelf' => 422,
        'CategoryInUse' => 409,
        'CategoryNotFound@POST /danbooru/category-mappings' => 422,
        'CycleDetected' => 409,
        'DuplicateMapping' => 409,
        'InvalidCategoryID' => 422,
        'InvalidColor' => 422,
        'InvalidInput' => 422,
        'InvalidTagID' => 422,
        'InvalidTagList' => 422,
        'InvalidTagName' => 422,
        'NameTaken' => 409,
        'NameTooLong' => 422,
        'NoFilesUploaded' => 422,
        'NoValidTagsSupplied' => 422,
        'TagAlreadyExists' => 409,
        'TagNameTooLong' => 422,
    ];

    /** The listings API 8 folded into GET /media, by the path they had. */
    private const string LISTING = '#^/api/media/(?:(?<kind>page|untagged)|with-tags/(?<tags>[^/]+))/(?<page>[^/]+)(?:/(?<perPage>[^/]+))?$#';

    /** What PUT was a partial update on, which API 8 makes a PATCH. */
    private const string PATCHED = '#^/api/(?:tags|tag-categories|danbooru/tag-mappings)/[^/]+$#';

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function request(array $request): array
    {
        $path = (string) $request['path'];
        if ($request['method'] === 'PUT' && preg_match(self::PATCHED, $path) === 1) {
            $request['method'] = 'PATCH';
        }

        if (preg_match(self::LISTING, $path, $listing) === 1) {
            $limit = $listing['perPage'] ?? '';
            $page = $listing['page'];
            $offset = ctype_digit($page) ? ((int) $page - 1) * ($limit !== '' && ctype_digit($limit) ? (int) $limit : 40) : $page;
            $query = match (true) {
                $listing['kind'] === 'untagged' => ['untagged' => 'true'],
                $listing['kind'] === 'page' => [],
                default => ['tags' => rawurldecode($listing['tags'])],
            };
            $query += ($limit !== '' ? ['limit' => $limit] : []) + ['offset' => (string) $offset];
            $request['path'] = '/api/media?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } elseif (str_contains($path, '?')) {
            [$base, $query] = explode('?', $path, 2);
            parse_str($query, $parameters);
            $request['path'] = $base . '?' . http_build_query(self::camelKeys($parameters), '', '&', PHP_QUERY_RFC3986);
        }

        foreach (['json', 'form'] as $body) {
            if (isset($request[$body]) && is_array($request[$body])) {
                $request[$body] = self::camelKeys($request[$body]);
            }
        }
        if (isset($request['multipart']) && is_array($request['multipart'])) {
            /** @var list<array<string, mixed>> $parts */
            $parts = $request['multipart'];
            $request['multipart'] = array_map(static fn(array $part): array => ['name' => self::camel((string) $part['name'])] + $part, $parts);
        }

        return $request;
    }

    /**
     * The recorded response as API 8 gives it, to the request as recorded.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function response(array $response, array $request, string $route): array
    {
        if (!array_key_exists('json', $response)) {
            return $response;
        }
        $json = $response['json'];
        /** @var array<string, string> $headers */
        $headers = $response['headers'] ?? [];

        if (is_array($json) && isset($json['error'], $json['message']) && is_string($json['error'])) {
            $code = $json['error'];
            $status = self::STATUS["{$code}@{$route}"] ?? self::STATUS[$code] ?? (int) $response['status'];

            return [
                'status' => $status,
                'headers' => ['content-type' => 'application/problem+json'] + $headers,
                'json' => [
                    'type' => ApiException::type($code),
                    'title' => Response::$statusTexts[$status] ?? 'Error',
                    'status' => $status,
                    'detail' => $json['message'],
                ],
            ];
        }

        $json = self::camelKeys($json);
        $path = (string) $request['path'];
        if (preg_match(self::LISTING, $path, $listing) === 1 && is_array($json) && isset($json['totalPages'])) {
            $limit = ($listing['perPage'] ?? '') !== '' ? $listing['perPage'] : '40';
            $headers['x-total-count'] = "<pages:{$json['totalPages']}/{$limit}>";
            unset($json['totalPages'], $json['currentPage']);
        }
        if ($request['method'] === 'GET' && $path === '/api/tags' && is_array($json)) {
            $headers['x-total-count'] = (string) count($json);
        }

        return ['headers' => $headers, 'json' => $json] + $response;
    }

    /** A recorded JSON value with its object keys camelCase and its file times RFC 3339. */
    private static function camelKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $converted = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $item = $key === 'file_time' && is_int($item) ? gmdate('Y-m-d\TH:i:s\Z', $item) : $item;
                $converted[self::camel($key)] = self::camelKeys($item);
            } else {
                $converted[$key] = self::camelKeys($item);
            }
        }

        return $converted;
    }

    private static function camel(string $name): string
    {
        return str_contains($name, '_') ? Str::camel($name) : $name;
    }
}
