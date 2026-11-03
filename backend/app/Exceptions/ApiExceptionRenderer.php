<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every failure under /api answered as `{ error, message }`, never Laravel's
 * HTML or `{ message }` pages: an unknown path is 404 NotFound, a known path
 * with the wrong method is 405 MethodNotAllowed (with Allow), and anything
 * uncaught is 500 ServerError. Errors the app raises itself are ApiExceptions,
 * which render themselves, and responses already built (the rate limiters')
 * pass through.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (!$request->is('api', 'api/*') || $e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof AuthenticationException => ApiException::response('Unauthorized', 401, 'Authentication is required. Please log in and try again.'),
            $e instanceof ValidationException => ApiException::response('InvalidInput', 400, $e->validator->errors()->first()),
            $e instanceof HttpExceptionInterface => $this->httpError($e, $request),
            default => ApiException::response('ServerError', 500, 'Something went wrong on the server. Please try again.'),
        };
    }

    private function httpError(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        /** @var array<string, string> $headers */
        $headers = $e->getHeaders();

        [$code, $message] = match ($status) {
            404 => ['NotFound', 'The requested endpoint does not exist.'],
            405 => ['MethodNotAllowed', "This endpoint does not support {$request->method()} requests."],
            default => [
                Str::studly(Response::$statusTexts[$status] ?? 'Error'),
                Response::$statusTexts[$status] ?? 'The request could not be completed.',
            ],
        };

        return ApiException::response($code, $status, $message, $headers);
    }
}
