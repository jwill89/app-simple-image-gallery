<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\OpenApi\ProblemJson;
use App\Support\ContentSecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use OpenApi\Spec as OAS;

/** The running version, the OpenAPI document, and its interactive reference. */
final class SystemController extends Controller
{
    /**
     * GET /version — The running application and API contract versions.
     */
    #[OAS\Operation\Get(
        path: '/version',
        operationId: 'getVersion',
        summary: 'Application and API version',
        tags: ['System'],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Version info',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/VersionResponse'),
            ),
        ],
    )]
    public function version(): JsonResponse
    {
        return new JsonResponse([
            'version' => config()->string('gallery.version'),
            'apiVersion' => config()->string('gallery.api_version'),
        ]);
    }

    /**
     * GET /openapi.json — The generated OpenAPI 3.1 document.
     *
     * Serves the spec committed to the repo (regenerated with `composer docs`),
     * so production does not depend on swagger-php being installed.
     */
    #[OAS\Operation\Get(
        path: '/openapi.json',
        operationId: 'getOpenApiSpec',
        summary: 'The OpenAPI 3.1 specification',
        tags: ['System'],
        responses: [
            new OAS\Response(response: 200, description: 'The OpenAPI document', content: new OAS\MediaType\Json(schema: new OAS\Schema(type: 'object', description: 'An OpenAPI 3.1 document: this one.'))),
            new OAS\Response(response: 404, description: 'SpecNotFound: the spec has not been generated', content: new ProblemJson()),
        ],
    )]
    public function openApi(): Response
    {
        $path = base_path('openapi.json');
        if (!File::isFile($path)) {
            throw new ApiException('SpecNotFound', 404, 'The OpenAPI spec has not been generated. Run `composer docs`.');
        }

        return new Response(File::get($path), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * GET /docs — The interactive API reference (Swagger UI), pointed at the spec.
     */
    #[OAS\Operation\Get(
        path: '/docs',
        operationId: 'getApiDocs',
        summary: 'Interactive API reference (Swagger UI)',
        tags: ['System'],
        responses: [
            new OAS\Response(response: 200, description: 'HTML documentation page'),
        ],
    )]
    public function docs(): Response
    {
        // Swagger UI, from the files the front end's build copies to /api-docs/
        // (frontend/vite.config.ts), under a policy that admits nothing else.
        $html = <<<'HTML'
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <meta name="robots" content="noindex" />
                <title>Gallery — API Reference</title>
                <link rel="stylesheet" href="/api-docs/swagger-ui.css" />
              </head>
              <body>
                <main id="reference"></main>
                <script src="/api-docs/swagger-ui-bundle.js"></script>
                <script src="/api-docs/reference.js"></script>
              </body>
            </html>
            HTML;

        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        ContentSecurityPolicy::apply($response, ContentSecurityPolicy::apiReference());

        return $response;
    }
}
