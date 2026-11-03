<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\ApiException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // config/cors.php is read before config/gallery.php, so it can't refer to it.
        config(['cors.allowed_origins' => config()->array('gallery.allowed_origins')]);

        // Resources are the response body itself, as the contract has them, not
        // wrapped in { data: … }.
        JsonResource::withoutWrapping();

        // Every /api route: 120 requests a minute per IP. A 429 says when to retry,
        // in the body and in Retry-After.
        RateLimiter::for('api', static fn(Request $request): Limit => Limit::perMinute(120)
            ->by((string) $request->ip())
            ->response(static fn(Request $request, array $headers) => ApiException::response(
                'RateLimitExceeded',
                429,
                'Too many requests. Please wait a moment and try again.',
                $headers,
                ['retry_after' => (int) $headers['Retry-After']],
            )));
    }
}
