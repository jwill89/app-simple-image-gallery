<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\DanbooruCategoryMapping;
use App\Models\DanbooruTagMapping;
use App\Models\FavoriteList;
use App\Models\Media;
use App\Models\SavedSearch;
use App\Models\Tag;
use App\Models\TagCategory;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every failure under /api answered as `{ error, message }`, never Laravel's
 * HTML or `{ message }` pages: an unknown path is 404 NotFound, a path id with
 * no record behind it 404 with the resource's own code (MediaNotFound, …), a
 * known path with the wrong method 405 MethodNotAllowed (with Allow), and
 * anything uncaught 500 ServerError. Errors the app raises itself are
 * ApiExceptions, which render themselves, and responses already built (the
 * rate limiters') pass through.
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
        // Laravel turns a route model binding's miss into a 404 around it.
        $missing = $e instanceof HttpException ? $e->getPrevious() : null;

        [$code, $message] = match (true) {
            $status === 404 && $missing instanceof ModelNotFoundException => self::missing($missing),
            $status === 404 => ['NotFound', 'The requested endpoint does not exist.'],
            $status === 405 => ['MethodNotAllowed', "This endpoint does not support {$request->method()} requests."],
            default => [
                Str::studly(Response::$statusTexts[$status] ?? 'Error'),
                Response::$statusTexts[$status] ?? 'The request could not be completed.',
            ],
        };

        return ApiException::response($code, $status, $message, $headers);
    }

    /**
     * The code and message for a path id with no record, by its model.
     *
     * @param ModelNotFoundException<\Illuminate\Database\Eloquent\Model> $e
     * @return array{0: string, 1: string}
     */
    private static function missing(ModelNotFoundException $e): array
    {
        return match ($e->getModel()) {
            Media::class => ['MediaNotFound', 'The media item could not be found.'],
            Tag::class => ['TagNotFound', 'The tag could not be found.'],
            TagCategory::class => ['CategoryNotFound', 'The category could not be found.'],
            DanbooruCategoryMapping::class, DanbooruTagMapping::class => ['MappingNotFound', 'The import rule could not be found.'],
            User::class => ['UserNotFound', 'The account could not be found.'],
            FavoriteList::class => ['ListNotFound', 'The list could not be found.'],
            SavedSearch::class => ['SearchNotFound', 'The saved search could not be found.'],
            default => ['NotFound', 'The requested record could not be found.'],
        };
    }
}
