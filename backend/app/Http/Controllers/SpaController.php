<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SPA shell, for every path outside /api that isn't a public file: the
 * built index.html (gallery.spa_index) with the page's link-preview tags, Open
 * Graph and Twitter card, so a link shared in a chat or on a social site shows
 * what it points at, and the FontAwesome kit. The SPA routes the path itself,
 * in the browser.
 */
final class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $index = config()->string('gallery.spa_index');
        if (!File::isFile($index)) {
            return new Response('index.html not found.', 500, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // The path as requested, still percent-encoded, and the page's absolute URL.
        $path = '/' . ltrim($request->getPathInfo(), '/');
        $base = $request->getSchemeAndHttpHost();
        ['title' => $title, 'description' => $description, 'image' => $image, 'card' => $card] = $this->preview($path, $base);

        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tags = implode("\n    ", [
            '<!-- Open Graph -->',
            '<meta property="og:title" content="' . $e($title) . '" />',
            '<meta property="og:description" content="' . $e($description) . '" />',
            '<meta property="og:image" content="' . $e($image) . '" />',
            '<meta property="og:url" content="' . $e($base . $path) . '" />',
            '<meta property="og:type" content="website" />',
            '<!-- Twitter Card -->',
            '<meta name="twitter:card" content="' . $e($card) . '" />',
            '<meta name="twitter:title" content="' . $e($title) . '" />',
            '<meta name="twitter:description" content="' . $e($description) . '" />',
            '<meta name="twitter:image" content="' . $e($image) . '" />',
        ]);

        $html = str_replace('</head>', "    {$tags}\n  </head>", File::get($index));
        $html = preg_replace('#<title>[^<]*</title>#', '<title>' . $e($title) . '</title>', $html) ?? $html;

        $kit = config()->string('gallery.fontawesome_kit_id');
        if ($kit !== '') {
            $script = '<script src="https://kit.fontawesome.com/' . $e($kit) . '.js" crossorigin="anonymous"></script>';
            $html = str_replace('</head>', "    {$script}\n  </head>", $html);
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * What a link to $path shows. Pages with nothing of their own to say get
     * the default, and so does any page whose lookup fails: a preview is never
     * worth an error page.
     *
     * @return array{title: string, description: string, image: string, card: string}
     */
    private function preview(string $path, string $base): array
    {
        $default = ['title' => 'Gallery', 'description' => 'A personal media gallery.', 'image' => "{$base}/favicon.png", 'card' => 'summary'];

        try {
            if (preg_match('#^/media/(\d+)/tags$#', $path, $match) === 1) {
                return $this->mediaPreview((int) $match[1], $base) ?? $default;
            }
            if (preg_match('#^/media/\d+/\d+/with-tags/(.+)$#', $path, $match) === 1) {
                return ['title' => 'Gallery — Tag Search', 'description' => self::searchDescription(urldecode($match[1]))] + $default;
            }
            if (preg_match('#^/media(?:/\d+(?:/\d+)?)?$#', $path) === 1) {
                return ['description' => 'Browse the media gallery.'] + $default;
            }
            if ($path === '/tags') {
                return ['title' => 'Gallery — Tags', 'description' => 'Browse all tags in the gallery.'] + $default;
            }
            if ($path === '/favorites') {
                return ['title' => 'Gallery — Favorites', 'description' => 'Your favorited media items.'] + $default;
            }
            if (preg_match('#^/tags/(\d+)$#', $path, $match) === 1) {
                $name = Tag::query()->whereKey((int) $match[1])->value('tag_name');

                return is_string($name) && $name !== ''
                    ? ['title' => "Tag: {$name}", 'description' => "Implications for the \"{$name}\" tag."] + $default
                    : ['title' => 'Gallery — Tag Details', 'description' => 'Tag details and implications.'] + $default;
            }
        } catch (Throwable $e) {
            Log::warning('Link preview lookup failed', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return $default;
    }

    /**
     * A media item's page: its type and id, its first 20 tags by name, and its
     * @2x thumbnail as a large image. Null when there is no such item.
     *
     * @return array{title: string, description: string, image: string, card: string}|null
     */
    private function mediaPreview(int $id, string $base): ?array
    {
        $media = Media::query()->find($id, ['media_id', 'file_name', 'media_type']);
        if ($media === null) {
            return null;
        }

        $type = ucfirst($media->media_type);
        $tags = $media->tags()->orderBy('tag_name')->limit(20)->pluck('tag_name')->all();

        return [
            'title' => "{$type} #{$id}",
            'description' => $tags !== [] ? implode(', ', $tags) : "{$type} in the gallery.",
            'image' => $base . '/media/thumbs/' . rawurlencode(pathinfo($media->file_name, PATHINFO_FILENAME) . '@2x.webp'),
            'card' => 'summary_large_image',
        ];
    }

    /** "cat, dog — excluding kitten": the searched tags, then the excluded ones. A leading + is dropped. */
    private static function searchDescription(string $tagList): string
    {
        $include = [];
        $exclude = [];
        foreach (array_map(trim(...), explode(',', $tagList)) as $name) {
            if (str_starts_with($name, '-')) {
                $exclude[] = ltrim($name, '-');
            } else {
                $include[] = ltrim($name, '+');
            }
        }

        return implode(' — ', [
            ...($include === [] ? [] : [implode(', ', $include)]),
            ...($exclude === [] ? [] : ['excluding ' . implode(', ', $exclude)]),
        ]);
    }
}
