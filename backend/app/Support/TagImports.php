<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use App\Services\Danbooru\DanbooruImport;
use Illuminate\Contracts\Cache\Repository;

/**
 * Where each media item's queued Danbooru import stands, kept a day, so an
 * upload can report its tags once the queue has fetched them
 * (GET /media/tag-imports).
 */
final readonly class TagImports
{
    private const int TTL = 86400;

    public function __construct(private Repository $cache) {}

    public function queued(Media $media): void
    {
        $this->put($media, ['status' => 'queued']);
    }

    public function finished(Media $media, DanbooruImport $import): void
    {
        $error = $import->failureException();
        $this->put($media, match (true) {
            $import->found => ['status' => 'applied', 'tags_applied' => $import->tagsApplied],
            $error !== null => ['status' => 'failed', 'error' => $error->getMessage()],
            default => ['status' => 'not_found'],
        });
    }

    public function failed(Media $media, string $error): void
    {
        $this->put($media, ['status' => 'failed', 'error' => $error]);
    }

    /**
     * Each item's status, in the order asked for.
     *
     * @param list<int> $mediaIds
     * @return list<array{media_id: int, status: string, tags_applied: int, error: ?string}>
     */
    public function statuses(array $mediaIds): array
    {
        return array_map(function (int $id): array {
            $stored = $this->cache->get(self::key($id));
            $stored = is_array($stored) ? $stored : ['status' => 'none'];

            return [
                'media_id' => $id,
                'status' => is_string($stored['status'] ?? null) ? $stored['status'] : 'none',
                'tags_applied' => is_int($stored['tags_applied'] ?? null) ? $stored['tags_applied'] : 0,
                'error' => is_string($stored['error'] ?? null) ? $stored['error'] : null,
            ];
        }, $mediaIds);
    }

    /** @param array<string, mixed> $status */
    private function put(Media $media, array $status): void
    {
        $this->cache->put(self::key($media->media_id), $status, self::TTL);
    }

    private static function key(int $mediaId): string
    {
        return "tag-import:{$mediaId}";
    }
}
