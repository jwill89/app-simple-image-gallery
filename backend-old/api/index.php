<?php

// Required Autoloader (use __DIR__ so this resolves regardless of CWD)
require_once(__DIR__ . '/../vendor/autoload.php');

// Set CWD to project root so all relative paths (media/full/, db/, etc.) resolve correctly.
// Without this, Apache may set CWD to api/ which breaks MediaCollection paths.
chdir(__DIR__ . '/..');

use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Routes\Internal\AuthController;
use Routes\Internal\DanbooruController;
use Routes\Internal\DuplicatesController;
use Routes\Internal\MediaController;
use Routes\Internal\SystemController;
use Routes\Internal\TagCategoryController;
use Routes\Internal\TagController;
use Routes\Internal\TagImplicationController;
use Routes\Internal\UploadController;
use Gallery\Core\Configuration;
use Gallery\Core\Logger;
use Gallery\Core\RateLimiter;

// Create Container using PHP-DI. Autowiring builds the Repository/Collection ->
// Controller graph; dependencies.php supplies the one thing it can't infer (PDO).
$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/dependencies.php');
$container = $builder->build();

// Register Container
AppFactory::setContainer($container);

// Set up the App and Log
$app = AppFactory::create();

// Set Base Path
$app->setBasePath("/api");

// Setup Middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Setup Error Middleware (disable detailed errors in production)
$error_middleware = $app->addErrorMiddleware(false, true, true);

// ============================================================
// Reusable Auth Token Verification
// ============================================================

/**
 * Verify a Bearer token from the Authorization header.
 * Returns true if valid, false otherwise.
 */
function verifyAuthToken(string $authHeader): bool
{
    $token = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';

    if ($token === '') {
        return false;
    }

    $db = \Gallery\Core\DatabaseConnection::getInstance();
    $stmt = $db->prepare('SELECT 1 FROM auth_tokens WHERE token = :token AND created_at >= :min_time');
    $stmt->execute([':token' => $token, ':min_time' => time() - 86400]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Create an unauthorized JSON response.
 */
function unauthorizedResponse(): ResponseInterface
{
    $response = new \Slim\Psr7\Response();
    $response->getBody()->write((string) json_encode([
        'error' => 'Unauthorized',
        'message' => 'Authentication is required. Please log in and try again.',
    ]));
    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
}

// ============================================================
// Auth Middleware for State-Changing Operations
// ============================================================
// All GETs are public. State-changing methods (POST/PUT/PATCH/DELETE) require a
// bearer token, except an intentionally-public allowlist matched by (method, path
// pattern): media tagging (anyone may tag) and the batched POST /media/by-ids read.
$authMiddleware = function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $method = strtoupper($request->getMethod());

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return $handler->handle($request);
    }

    // Public state-changing routes, matched precisely by method + path pattern so
    // that (e.g.) DELETE /media/{id}/tags/{tagId} stays public while DELETE
    // /media/{id} still requires a token.
    $path = rtrim($request->getUri()->getPath(), '/');
    $publicWrites = [
        ['POST', '#/media/by-ids$#'],
        ['PATCH', '#/media/\d+/tags$#'],
        ['DELETE', '#/media/\d+/tags/\d+$#'],
    ];
    foreach ($publicWrites as [$verb, $pattern]) {
        if ($method === $verb && preg_match($pattern, $path) === 1) {
            return $handler->handle($request);
        }
    }

    if (!verifyAuthToken($request->getHeaderLine('Authorization'))) {
        return unauthorizedResponse();
    }

    return $handler->handle($request);
};

// Rate Limiting Middleware
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $rateLimiter = new RateLimiter(\Gallery\Core\DatabaseConnection::getInstance(), 120, 60);
    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
    $result = $rateLimiter->check($ip);

    if (!$result['allowed']) {
        Logger::getInstance()->warning('Rate limit exceeded', ['ip' => $ip, 'retry_after' => $result['retry_after']]);
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write((string) json_encode([
            'error' => 'RateLimitExceeded',
            'message' => 'Too many requests. Please wait a moment and try again.',
            'retry_after' => $result['retry_after'],
        ]));
        return $response
            ->withStatus(429)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $result['retry_after'])
            ->withHeader('X-RateLimit-Remaining', '0');
    }

    $response = $handler->handle($request);
    return $response->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
});

// CSRF Origin Check Middleware
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $method = strtoupper($request->getMethod());

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $origin = $request->getHeaderLine('Origin');
        $referer = $request->getHeaderLine('Referer');
        $allowedOrigins = Configuration::getAllowedOrigins();

        if ($origin !== '') {
            // Validate the Origin header directly.
            $rejected = !in_array($origin, $allowedOrigins, true);
        } elseif ($referer !== '') {
            // Fall back to deriving the origin from the Referer header.
            $refererOrigin = parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST);
            $port = parse_url($referer, PHP_URL_PORT);
            if (is_int($port) && $port > 0) {
                $refererOrigin .= ':' . $port;
            }
            $rejected = !in_array($refererOrigin, $allowedOrigins, true);
        } else {
            // Neither Origin nor Referer present: reject state-changing requests.
            // Browsers always send at least one for cross-/same-origin writes;
            // their absence indicates a non-browser client bypassing CORS (e.g. curl).
            $rejected = true;
        }

        if ($rejected) {
            Logger::getInstance()->warning('CSRF rejected', ['origin' => $origin, 'referer' => $referer]);
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'error' => 'ForbiddenOrigin',
                'message' => 'The request origin is not allowed.',
            ], JSON_THROW_ON_ERROR));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
    }

    return $handler->handle($request);
});

// CORS headers
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    if (strtoupper($request->getMethod()) === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
    } else {
        $response = $handler->handle($request);
    }

    $origin = $request->getHeaderLine('Origin');
    $allowed_origins = Configuration::getAllowedOrigins();

    $response = $response
        ->withHeader('X-Frame-Options', 'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options', 'nosniff');

    if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
        $response = $response
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    return $response;
});

// CORS Preflight OPTIONS Handler
$app->options('/{routes:.+}', fn(ServerRequestInterface $request, ResponseInterface $response) => $response->withStatus(204));

// ============================================================
// System (version + API docs) — all public reads
// ============================================================
$app->get('/version[/]', SystemController::class . ':getVersion');
$app->get('/openapi.json', SystemController::class . ':getOpenApiSpec');
$app->get('/docs[/]', SystemController::class . ':getDocs');

// ============================================================
// Authentication
// ============================================================
$app->post('/auth/login[/]', AuthController::class . ':login');

// ============================================================
// Media (unified images + videos) + media-scoped tagging
// ============================================================
$app->group('/media', function (RouteCollectorProxy $group) {
    // Listing/search + RPC reads (static segments before the {media_id} placeholder)
    $group->get('/page/{page}[/{per_page}[/]]', MediaController::class . ':getItemsForPage');
    $group->get('/untagged/{page}[/{per_page}[/]]', MediaController::class . ':getUntaggedItems');
    $group->get('/with-tags/{tag_list}/{page}[/{per_page}[/]]', MediaController::class . ':getItemsWithTags');
    $group->get('/random[/]', MediaController::class . ':getRandomItem');
    $group->get('/count[/]', MediaController::class . ':getCount');
    $group->post('/by-ids[/]', MediaController::class . ':getItemsByIds');
    $group->post('/bulk-delete[/]', MediaController::class . ':bulkDelete');
    // Upload = create a media resource
    $group->post('[/]', UploadController::class . ':create');
    // Single item
    $group->get('/{media_id}[/]', MediaController::class . ':getItem');
    $group->delete('/{media_id}[/]', MediaController::class . ':deleteItem');
    // Media-scoped tags
    $group->get('/{media_id}/tags[/]', MediaController::class . ':getMediaTags');
    $group->patch('/{media_id}/tags[/]', MediaController::class . ':addMediaTags');
    $group->delete('/{media_id}/tags/{tag_id}[/]', MediaController::class . ':removeMediaTag');
    // Danbooru tag import for a single media item
    $group->post('/{media_id}/danbooru-tags[/]', MediaController::class . ':fetchDanbooruTags');
})->add($authMiddleware);

// ============================================================
// Tags
// ============================================================
$app->group('/tags', function (RouteCollectorProxy $group) {
    $group->get('[/]', TagController::class . ':getAllTags');
    $group->get('/display[/]', TagController::class . ':getTagListForDisplay');
    $group->get('/{tag_id}[/]', TagController::class . ':getTag');
    $group->post('[/]', TagController::class . ':addTag');
    $group->put('/{tag_id}[/]', TagController::class . ':editTag');
    $group->delete('/{tag_id}[/]', TagController::class . ':deleteTag');
    $group->delete('/{tag_id}/migrate-to/{target_tag_id}[/]', TagController::class . ':deleteTag');
    $group->post('/{tag_id}/migrate[/]', TagController::class . ':migrateTag');
})->add($authMiddleware);

// ============================================================
// Tag Categories
// ============================================================
$app->group('/tag-categories', function (RouteCollectorProxy $group) {
    $group->get('[/]', TagCategoryController::class . ':getCategories');
    $group->post('[/]', TagCategoryController::class . ':addCategory');
    $group->put('/{category_id}[/]', TagCategoryController::class . ':editCategory');
    $group->delete('/{category_id}[/]', TagCategoryController::class . ':deleteCategory');
})->add($authMiddleware);

// ============================================================
// Tag Implications
// ============================================================
$app->group('/tag-implications', function (RouteCollectorProxy $group) {
    $group->get('[/]', TagImplicationController::class . ':getImplications');
    $group->post('[/]', TagImplicationController::class . ':addImplication');
    $group->delete('/{tag_id}/{implied_tag_id}[/]', TagImplicationController::class . ':removeImplication');
})->add($authMiddleware);

// ============================================================
// Danbooru Import Rules
// ============================================================
$app->group('/danbooru', function (RouteCollectorProxy $group) {
    $group->get('/category-mappings[/]', DanbooruController::class . ':getCategoryMappings');
    $group->post('/category-mappings[/]', DanbooruController::class . ':addCategoryMapping');
    $group->delete('/category-mappings/{danbooru_category_id}[/]', DanbooruController::class . ':deleteCategoryMapping');
    $group->get('/tag-mappings[/]', DanbooruController::class . ':getTagMappings');
    $group->post('/tag-mappings[/]', DanbooruController::class . ':addTagMapping');
    $group->put('/tag-mappings/{id}[/]', DanbooruController::class . ':editTagMapping');
    $group->delete('/tag-mappings/{id}[/]', DanbooruController::class . ':deleteTagMapping');
})->add($authMiddleware);

// ============================================================
// Duplicates
// ============================================================
$app->group('/duplicates', function (RouteCollectorProxy $group) {
    $group->get('/report[/]', DuplicatesController::class . ':getLatestReport');
    $group->post('/scan[/]', DuplicatesController::class . ':runScan');
    $group->post('/dismissals[/]', DuplicatesController::class . ':dismissPair');
})->add($authMiddleware);

// Run the app
$app->run();
