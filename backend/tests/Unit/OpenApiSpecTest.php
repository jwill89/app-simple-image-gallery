<?php

declare(strict_types=1);

use Tests\Support\OpenApiDocument;

// The OpenAPI contract: the #[OAS\*] attributes build a document without
// errors, the committed openapi.json is that document (regenerate it with
// `composer docs`), and every operation has a readable, unique operationId,
// which the frontend's generated types key their operations by.

test('the attributes build a document without errors', function (): void {
    expect(OpenApiDocument::builder()->build()->isValid())->toBeTrue();
});

test('the committed openapi.json is current', function (): void {
    expect(OpenApiDocument::outputPath())->toBeFile();

    expect(file_get_contents(OpenApiDocument::outputPath()))->toBe(OpenApiDocument::builder()->build()->toJson());
});

test('every operation has its own readable operationId', function (): void {
    /** @var array{paths: array<string, array<string, array{operationId?: string}>>} $spec */
    $spec = OpenApiDocument::builder()->build()->toArray();

    $ids = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $ids["{$method} {$path}"] = $operation['operationId'] ?? '';
        }
    }

    // API 4's 43, and API 5's logout, cursor feed and tag-import status.
    expect($ids)->toHaveCount(46)
        ->and(array_unique($ids))->toHaveCount(count($ids));
    foreach ($ids as $operation => $id) {
        expect($id)->toMatch('/^[a-z][a-zA-Z]+$/', "{$operation} has no readable operationId.");
    }
});
