<?php

declare(strict_types=1);

/**
 * `composer docs`: regenerate openapi.json from the #[OAS\*] attributes, built
 * the way OpenApiSpecTest checks it (tests/Support/OpenApiDocument.php).
 */

use Gallery\Tests\Support\OpenApiDocument;

require __DIR__ . '/../vendor/autoload.php';

$result = OpenApiDocument::builder()->build();

if (!$result->isValid()) {
    fwrite(STDERR, "Building the OpenAPI document reported errors; openapi.json was left as it was.\n");
    exit(1);
}

$result->saveAs(OpenApiDocument::outputPath(), 'json');
