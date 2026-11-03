<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An API error with its contract shape: `{ "error": <PascalCaseCode>,
 * "message": <human text> }` and a 4xx or 5xx status. The code is stable and
 * the frontend may branch on it; the message is for display and may change.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra Fields beside error and message, as a failed validation's errors.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The error body as a response. $extra adds fields beside error and message,
     * as the rate limiter's retry_after.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     */
    public static function response(string $errorCode, int $status, string $message, array $headers = [], array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => $errorCode, 'message' => $message] + $extra, $status, $headers);
    }

    public function render(): JsonResponse
    {
        return self::response($this->errorCode, $this->status, $this->getMessage(), $this->headers, $this->extra);
    }
}
