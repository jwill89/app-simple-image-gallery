<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An API error, answered as an RFC 9457 problem details body
 * (`application/problem+json`): `{ type, title, status, detail }`, where `type`
 * names the failure with a tag URI ending in its PascalCase name
 * (`tag:gallery.mathdad.me,2026:MediaNotFound`), which a client may branch on,
 * `title` is the status's reason phrase and `detail` the message a person can
 * read. A failed validation adds `errors`, one `{ message, location }` per
 * broken field.
 */
final class ApiException extends RuntimeException
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /** What every problem type starts with: a tag URI, which names and is never fetched. */
    public const string TYPE_PREFIX = 'tag:gallery.mathdad.me,2026:';

    /**
     * @param array<string, string> $headers
     * @param list<array{message: string, location: string}> $errors A failed validation's broken fields.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The problem details body as a response.
     *
     * @param array<string, string> $headers
     * @param list<array{message: string, location: string}> $errors
     */
    public static function response(string $errorCode, int $status, string $detail, array $headers = [], array $errors = []): JsonResponse
    {
        $body = [
            'type' => self::type($errorCode),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, ['Content-Type' => self::CONTENT_TYPE, ...$headers]);
    }

    /** The problem type that names a failure, from its PascalCase name. */
    public static function type(string $errorCode): string
    {
        return self::TYPE_PREFIX . $errorCode;
    }

    public function render(): JsonResponse
    {
        return self::response($this->errorCode, $this->status, $this->getMessage(), $this->headers, $this->errors);
    }
}
