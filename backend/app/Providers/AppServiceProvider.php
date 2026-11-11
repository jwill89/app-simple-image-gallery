<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\ApiException;
use App\Models\DanbooruCategoryMapping;
use App\Models\DanbooruTagMapping;
use App\Models\FavoriteList;
use App\Models\Media;
use App\Models\SavedSearch;
use App\Models\Tag;
use App\Models\TagCategory;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Passkeys go through PasskeyController, under /api with the rest; the
        // package's own routes would keep the challenge in a web session.
        Passkeys::ignoreRoutes();
    }

    public function boot(): void
    {
        // config/cors.php and config/filesystems.php are read before
        // config/gallery.php, so they can't refer to it.
        config(['cors.allowed_origins' => config()->array('gallery.allowed_origins')]);
        config(['filesystems.links' => [public_path('media') => config()->string('gallery.media_path')]]);

        // Resources are the response body itself, as the contract has them, not
        // wrapped in { data: … }.
        JsonResource::withoutWrapping();

        // An id in a path is digits, and names a record that exists: anything
        // else is a 404 (ApiExceptionRenderer names the missing resource).
        $models = [
            'media_id' => Media::class,
            'tag_id' => Tag::class,
            'target_tag_id' => Tag::class,
            'implied_tag_id' => Tag::class,
            'category_id' => TagCategory::class,
            'danbooru_category_id' => DanbooruCategoryMapping::class,
            // A Danbooru tag mapping's; the only route with a bare {id}.
            'id' => DanbooruTagMapping::class,
            'user_id' => User::class,
            'list_id' => FavoriteList::class,
            'search_id' => SavedSearch::class,
            'passkey_id' => Passkey::class,
        ];
        foreach ($models as $parameter => $model) {
            Route::pattern($parameter, '[0-9]+');
            Route::model($parameter, $model);
        }

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

        // Queued Danbooru imports (ImportDanbooruTags): about one every two
        // seconds, so a bulk import can't hammer Danbooru.
        RateLimiter::for('danbooru', static fn(): Limit => Limit::perMinute(30));
    }
}
