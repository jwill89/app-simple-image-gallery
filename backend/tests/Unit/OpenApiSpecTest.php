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

test('every $ref names a schema the document has', function (): void {
    $json = OpenApiDocument::builder()->build()->toJson();
    /** @var array{components: array{schemas: array<string, mixed>}} $spec */
    $spec = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    preg_match_all('~"\$ref":\s*"#/components/schemas/([^"]+)"~', $json, $refs);

    // A schema on a resource is found only when OpenApiDocument lists it.
    expect(array_diff(array_unique($refs[1]), array_keys($spec['components']['schemas'])))->toBe([]);
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

    // The count moves with every operation added or removed, so it also catches
    // one that stops being scanned.
    expect($ids)->toHaveCount(78)
        ->and(array_unique($ids))->toHaveCount(count($ids));
    foreach ($ids as $operation => $id) {
        expect($id)->toMatch('/^[a-z][a-zA-Z]+$/', "{$operation} has no readable operationId.");
    }
});
