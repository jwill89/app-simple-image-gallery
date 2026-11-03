<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/** The running version, the OpenAPI document, and its interactive reference. */
final class SystemController extends Controller
{
    /** GET /version: the application and API contract versions. */
    public function version(): JsonResponse
    {
        return new JsonResponse([
            'version' => config()->string('gallery.version'),
            'api_version' => config()->string('gallery.api_version'),
        ]);
    }

    /**
     * GET /openapi.json: the committed spec (regenerated with `composer docs`),
     * so production doesn't need swagger-php installed.
     */
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

    /** GET /docs: the Scalar API reference, pointed at the spec. */
    public function docs(): Response
    {
        $html = <<<'HTML'
            <!doctype html>
            <html>
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>Gallery — API Reference</title>
              </head>
              <body>
                <script id="api-reference" data-url="/api/openapi.json"></script>
                <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
              </body>
            </html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
