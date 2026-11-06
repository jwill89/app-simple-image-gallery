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

    // API 4's 43; API 5's logout, cursor feed and tag-import status; API 6's
    // accounts: 7 for sign-up and an account's own settings, 10 for lists, 4
    // for saved searches and 3 for the admin's Users page; and the sign-up and
    // login pages' settings (GET /auth/config).
    expect($ids)->toHaveCount(78)
        ->and(array_unique($ids))->toHaveCount(count($ids));
    foreach ($ids as $operation => $id) {
        expect($id)->toMatch('/^[a-z][a-zA-Z]+$/', "{$operation} has no readable operationId.");
    }
});
