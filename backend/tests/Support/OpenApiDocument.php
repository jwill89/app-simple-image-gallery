<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Resources\FavoriteListResource;
use App\Http\Resources\MediaResource;
use App\Http\Resources\SavedSearchResource;
use App\Http\Resources\TagCategoryResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\UserResource;
use OpenApi\Augmenter\Properties;
use OpenApi\Builder;
use OpenApi\Utils\Pipeline;
use ReflectionClass;

/**
 * How the OpenAPI document is built from the #[OAS\*] attributes, shared by
 * `composer docs` (bin/openapi.php, which writes openapi.json) and
 * OpenApiSpecTest (which checks the committed file against it), so the two
 * can't drift apart. Dev-only, like swagger-php itself.
 */
final class OpenApiDocument
{
    private const string BACKEND = __DIR__ . '/../..';

    /**
     * The resources that carry an entity schema. They are listed rather than
     * scanned so the schemas keep the order the document has always had,
     * after the other response shapes.
     */
    private const array SCHEMA_RESOURCES = [
        MediaResource::class,
        TagResource::class,
        TagCategoryResource::class,
        UserResource::class,
        FavoriteListResource::class,
        SavedSearchResource::class,
    ];

    public static function builder(): Builder
    {
        return new Builder()
            ->setSources([
                self::BACKEND . '/app/Http/Controllers/Api',
                self::BACKEND . '/app/OpenApi',
                array_map(static fn(string $class): ReflectionClass => new ReflectionClass($class), self::SCHEMA_RESOURCES),
            ])
            ->setVersion('3.1.0')
            // swagger-php 7's Properties augmenter turns PHP property initialisers
            // into schema `default`s. Every one here is a placeholder on a doc-only
            // response class (`public bool $success = true` on ScanResult), not a
            // value the API fills in for a missing field, so all it would add are
            // misleading. Where the API has a default, the attribute says so.
            ->withAugmenters(static fn(Pipeline $augmenters): Pipeline => $augmenters->remove(Properties::class));
    }

    public static function outputPath(): string
    {
        return self::BACKEND . '/openapi.json';
    }
}
