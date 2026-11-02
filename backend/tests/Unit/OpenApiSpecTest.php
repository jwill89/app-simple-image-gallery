<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit;

use Gallery\Tests\Support\OpenApiDocument;
use OpenApi\Builder\Result;
use PHPUnit\Framework\TestCase;

/**
 * Guards the OpenAPI contract:
 *  - the `#[OAS\*]` attributes across api/ + includes/ produce a document with
 *    no errors reported while building it;
 *  - the committed openapi.json is up to date (regenerate with `composer docs`).
 */
final class OpenApiSpecTest extends TestCase
{
    /**
     * Scan the attributes and build the document, as `composer docs` does.
     */
    private function generateSpec(): Result
    {
        return OpenApiDocument::builder()->build();
    }

    public function testSpecIsValid(): void
    {
        $this->assertTrue($this->generateSpec()->isValid(), 'Building the OpenAPI document reported errors.');
    }

    public function testCoreResourcesAreDocumented(): void
    {
        $spec = $this->generateSpec()->toArray();

        foreach (['/media', '/media/{media_id}', '/tags', '/tag-categories', '/tag-implications', '/auth/login', '/version'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "Missing documented path: {$path}");
        }

        foreach (['Media', 'Tag', 'TagCategory', 'MediaPage', 'ErrorResponse'] as $schema) {
            $this->assertArrayHasKey($schema, $spec['components']['schemas'], "Missing schema: {$schema}");
        }

        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    public function testCommittedSpecIsUpToDate(): void
    {
        $this->assertFileExists(OpenApiDocument::outputPath(), 'openapi.json is missing — run `composer docs`.');

        $fresh = $this->generateSpec()->toArray();
        $committed = json_decode((string) file_get_contents(OpenApiDocument::outputPath()), true);

        $this->assertEquals(
            $fresh,
            $committed,
            'backend/openapi.json is out of date — run `composer docs` and commit the result.'
        );
    }
}
