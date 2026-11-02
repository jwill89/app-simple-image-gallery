<?php

declare(strict_types=1);

namespace Gallery\Tests\Support;

use OpenApi\Augmenter\Properties;
use OpenApi\Builder;
use OpenApi\Utils\Pipeline;

/**
 * How the OpenAPI document is built from the #[OAS\*] attributes, shared by
 * `composer docs` (bin/openapi.php, which writes openapi.json) and
 * OpenApiSpecTest (which checks the committed file against it), so the two
 * can't drift apart. Dev-only, like swagger-php itself.
 */
final class OpenApiDocument
{
    private const string BACKEND = __DIR__ . '/../..';

    public static function builder(): Builder
    {
        return new Builder()
            ->setSources([self::BACKEND . '/api', self::BACKEND . '/includes'])
            ->setVersion('3.1.0')
            // swagger-php 7's Properties augmenter turns PHP property initialisers
            // into schema `default`s. Every one here is a placeholder on a doc-only
            // response class (`public bool $success = true` on ScanResult), not a
            // value the API fills in for a missing field, so all 81 it would add
            // are misleading. Where the API has a default, the attribute says so.
            ->withAugmenters(static fn(Pipeline $augmenters): Pipeline => $augmenters->remove(Properties::class));
    }

    public static function outputPath(): string
    {
        return self::BACKEND . '/openapi.json';
    }
}
