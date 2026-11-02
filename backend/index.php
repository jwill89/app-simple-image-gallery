<?php

/**
 * Front controller for the Gallery SPA.
 *
 * - Loads configuration from .env via the Configuration class
 * - Injects Open Graph meta tags for social platform link previews
 * - Injects the FontAwesome kit script from .env
 * - Serves dist/index.html with modifications applied
 *
 * All non-file, non-API requests are routed here by .htaccess.
 * The Vue SPA still handles client-side routing as normal.
 */

require_once(__DIR__ . '/vendor/autoload.php');

use Gallery\Core\Configuration;
use Gallery\Core\DatabaseConnection;

// ─── Resolve request path ───────────────────────────────────────────
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestUri = '/' . ltrim(is_string($requestUri) ? $requestUri : '', '/');

// ─── Build absolute base URL ────────────────────────────────────────
$https  = $_SERVER['HTTPS'] ?? '';
$scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

// ─── Default OG values ─────────────────────────────────────────────
$ogTitle       = 'Gallery';
$ogDescription = 'A personal media gallery.';
$ogImage       = $baseUrl . '/favicon.png';
$ogUrl         = $baseUrl . $requestUri;
$ogType        = 'website';
$twitterCard   = 'summary';

// ─── Route-specific OG tags ─────────────────────────────────────────

try {
    // Media detail page: /media/:id/tags
    if (preg_match('#^/media/(\d+)/tags$#', $requestUri, $m) === 1) {
        $mediaId = (int) $m[1];
        $db = DatabaseConnection::getInstance();

        $stmt = $db->prepare('SELECT media_id, file_name, media_type FROM media WHERE media_id = :id');
        $stmt->execute([':id' => $mediaId]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($media !== false) {
            $baseName = pathinfo($media['file_name'], PATHINFO_FILENAME);

            $ogTitle     = ucfirst($media['media_type']) . ' #' . $mediaId;
            $ogImage     = $baseUrl . '/media/thumbs/' . rawurlencode($baseName . '@2x.webp');
            $twitterCard = 'summary_large_image';

            // Fetch tags for the description
            $tagStmt = $db->prepare(
                'SELECT t.tag_name
                 FROM tags t
                 JOIN media_tags mt ON t.tag_id = mt.tag_id
                 WHERE mt.media_id = :id
                 ORDER BY t.tag_name
                 LIMIT 20'
            );
            $tagStmt->execute([':id' => $mediaId]);
            $tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($tags !== []) {
                $ogDescription = implode(', ', $tags);
            } else {
                $ogDescription = ucfirst($media['media_type']) . ' in the gallery.';
            }
        }

    // Gallery with tag filter: /media/:page/:perPage/with-tags/:tags
    } elseif (preg_match('#^/media/\d+/\d+/with-tags/(.+)$#', $requestUri, $m) === 1) {
        $tagString = urldecode($m[1]);
        $tagNames  = array_map(trim(...), explode(',', $tagString));

        // Separate include (+/default) and exclude (-) tags for display
        $includes = [];
        $excludes = [];
        foreach ($tagNames as $t) {
            if (str_starts_with($t, '-')) {
                $excludes[] = ltrim($t, '-');
            } else {
                $includes[] = ltrim($t, '+');
            }
        }

        $parts = [];
        if ($includes !== []) {
            $parts[] = implode(', ', $includes);
        }
        if ($excludes !== []) {
            $parts[] = 'excluding ' . implode(', ', $excludes);
        }

        $ogTitle       = 'Gallery — Tag Search';
        $ogDescription = $parts !== [] ? implode(' — ', $parts) : 'Browsing by tags.';

    // Gallery list: /media or /media/:page/:perPage
    } elseif (preg_match('#^/media(?:/\d+(?:/\d+)?)?$#', $requestUri) === 1) {
        $ogTitle       = 'Gallery';
        $ogDescription = 'Browse the media gallery.';

    // Tag list page
    } elseif ($requestUri === '/tags') {
        $ogTitle       = 'Gallery — Tags';
        $ogDescription = 'Browse all tags in the gallery.';

    // Favorites page
    } elseif ($requestUri === '/favorites') {
        $ogTitle       = 'Gallery — Favorites';
        $ogDescription = 'Your favorited media items.';

    // Tag implications page: /tags/:id
    } elseif (preg_match('#^/tags/(\d+)$#', $requestUri, $m) === 1) {
        $tagId = (int) $m[1];
        $db = DatabaseConnection::getInstance();

        $stmt = $db->prepare('SELECT tag_name FROM tags WHERE tag_id = :id');
        $stmt->execute([':id' => $tagId]);
        $tagName = $stmt->fetchColumn();

        if (is_string($tagName) && $tagName !== '') {
            $ogTitle       = 'Tag: ' . $tagName;
            $ogDescription = 'Implications for the "' . $tagName . '" tag.';
        } else {
            $ogTitle       = 'Gallery — Tag Details';
            $ogDescription = 'Tag details and implications.';
        }
    }
} catch (\Throwable) {
    // Silently fall through to defaults — OG tags are best-effort
}

// ─── Escape all dynamic values for safe HTML embedding ──────────────
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$ogMeta = implode("\n    ", [
    '<!-- Open Graph -->',
    '<meta property="og:title" content="' . $e($ogTitle) . '" />',
    '<meta property="og:description" content="' . $e($ogDescription) . '" />',
    '<meta property="og:image" content="' . $e($ogImage) . '" />',
    '<meta property="og:url" content="' . $e($ogUrl) . '" />',
    '<meta property="og:type" content="' . $e($ogType) . '" />',
    '<!-- Twitter Card -->',
    '<meta name="twitter:card" content="' . $e($twitterCard) . '" />',
    '<meta name="twitter:title" content="' . $e($ogTitle) . '" />',
    '<meta name="twitter:description" content="' . $e($ogDescription) . '" />',
    '<meta name="twitter:image" content="' . $e($ogImage) . '" />',
]);

// ─── Read index.html and inject tags ────────────────────────────────
$htmlPath = __DIR__ . '/dist/index.html';

if (!file_exists($htmlPath)) {
    http_response_code(500);
    echo 'index.html not found.';
    exit(1);
}

$html = file_get_contents($htmlPath);

if ($html === false) {
    http_response_code(500);
    echo 'index.html could not be read.';
    exit(1);
}

// Inject OG meta tags before </head>
$html = str_replace('</head>', "    {$ogMeta}\n  </head>", $html);

// Update the <title> tag to match (preg_replace returns null only on a regex
// engine failure; keep the page as it was rather than serve nothing)
$html = preg_replace('#<title>[^<]*</title>#', '<title>' . $e($ogTitle) . '</title>', $html) ?? $html;

// Inject FontAwesome kit script from .env (replaces hardcoded kit in HTML)
$faKitId = Configuration::getFontAwesomeKitId();
if ($faKitId !== '') {
    $faScript = '<script src="https://kit.fontawesome.com/' . $e($faKitId) . '.js" crossorigin="anonymous"></script>';
    // Replace any existing kit script, or inject before </head> if not present
    if (preg_match('#<script[^>]*kit\.fontawesome\.com[^>]*></script>#', $html) === 1) {
        $html = preg_replace('#<script[^>]*kit\.fontawesome\.com[^>]*></script>#', $faScript, $html) ?? $html;
    } else {
        $html = str_replace('</head>', "    {$faScript}\n  </head>", $html);
    }
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
