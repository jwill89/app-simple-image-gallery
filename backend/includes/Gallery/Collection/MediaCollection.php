<?php

namespace Gallery\Collection;

use OutOfBoundsException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\ImageHash\ImageHasher;
use Intervention\ImageHash\Strategies\PerceptualStrategy;
use Gallery\Core\Configuration;
use Gallery\Core\MediaThumbnail;
use Gallery\Core\MediaMetadata;
use Gallery\Storage\MediaStorage;
use Gallery\Structure\Media;

/**
 * MediaCollection class
 * Unified collection managing all media items (images and videos).
 * Handles database operations, thumbnails, and fingerprinting.
 */
class MediaCollection
{
    // Unified directory constants
    public const string MEDIA_DIRECTORY = 'media/';
    public const string MEDIA_DIRECTORY_FULL = 'media/full/';
    public const string MEDIA_DIRECTORY_THUMBNAILS = 'media/thumbs/';

    /**
     * Extensions classified as images (static GIFs included here for extension
     * validation). detectMediaType() treats everything else as video, so there
     * is no separate video-extension list to maintain here.
     */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif'];

    public function __construct(private readonly MediaStorage $storage)
    {
    }

    /**
     * Gets a media item by ID.
     */
    public function get(int $media_id): ?Media
    {
        return $this->storage->retrieve($media_id);
    }

    /**
     * Gets all media items.
     *
     * @return Media[]
     */
    public function getAll(): array
    {
        return $this->storage->retrieve();
    }

    /**
     * Gets a lightweight summary of all media (or filtered by type).
     *
     * @return list<array<string, mixed>>
     */
    public function getAllSummary(?string $media_type = null): array
    {
        return $this->storage->retrieveSummary($media_type);
    }

    /**
     * Gets a single random media item.
     */
    public function getRandom(): ?Media
    {
        return $this->storage->retrieveRandom();
    }

    /**
     * Gets paginated media items.
     *
     * @return Media[]
     */
    public function getForPage(int $page_number, ?int $items_per_page = null): array
    {
        $items_per_page ??= Configuration::DEFAULT_PER_PAGE;
        return $this->storage->retrieveForPage($page_number, $items_per_page);
    }

    /**
     * Gets paginated media filtered by tags.
     *
     * @param int[] $tag_ids
     * @return Media[]
     */
    public function getWithTags(array $tag_ids, int $page_number, ?int $items_per_page = null): array
    {
        $items_per_page ??= Configuration::DEFAULT_PER_PAGE;
        return $this->storage->retrieveWithTags($tag_ids, $page_number, $items_per_page);
    }

    /**
     * Gets media matching included/excluded tags.
     *
     * @param int[] $include_tag_ids
     * @param int[] $exclude_tag_ids
     * @return Media[]
     */
    public function getWithTagFilter(array $include_tag_ids, array $exclude_tag_ids, int $page_number, ?int $items_per_page = null): array
    {
        $items_per_page ??= Configuration::DEFAULT_PER_PAGE;
        return $this->storage->retrieveWithTagFilter($include_tag_ids, $exclude_tag_ids, $page_number, $items_per_page);
    }

    /**
     * Gets total count of media matching included/excluded tags.
     *
     * @param int[] $include_tag_ids
     * @param int[] $exclude_tag_ids
     */
    public function totalWithTagFilter(array $include_tag_ids, array $exclude_tag_ids): int
    {
        return $this->storage->retrieveTotalWithTagFilterCount($include_tag_ids, $exclude_tag_ids);
    }

    /**
     * Gets total media count.
     */
    public function totalMedia(): int
    {
        return $this->storage->retrieveTotalCount();
    }

    /**
     * Gets untagged media for a page.
     *
     * @return Media[]
     */
    public function getUntagged(int $page_number, ?int $items_per_page = null): array
    {
        $items_per_page ??= Configuration::DEFAULT_PER_PAGE;
        return $this->storage->retrieveUntaggedForPage($page_number, $items_per_page);
    }

    /**
     * Gets total untagged count.
     */
    public function totalUntagged(): int
    {
        return $this->storage->retrieveTotalUntaggedCount();
    }

    /**
     * Gets total media with specific tags.
     *
     * @param int[] $tag_ids
     */
    public function totalMediaWithTags(array $tag_ids): int
    {
        return $this->storage->retrieveTotalWithTagsCount($tag_ids);
    }

    /**
     * Gets multiple media items by their IDs.
     * Missing IDs are silently skipped.
     *
     * @param int[] $mediaIds
     * @return Media[]
     */
    public function getByIds(array $mediaIds): array
    {
        return $this->storage->retrieveByIds($mediaIds);
    }

    /**
     * Finds an existing media item by its MD5 hash.
     */
    public function findIdByHash(string $hash): ?int
    {
        return $this->storage->retrieveIdByHash($hash);
    }

    /**
     * Returns the full-size file directory.
     */
    public static function getFullDirectory(): string
    {
        return self::MEDIA_DIRECTORY_FULL;
    }

    /**
     * Returns the thumbnail directory.
     */
    public static function getThumbDirectory(): string
    {
        return self::MEDIA_DIRECTORY_THUMBNAILS;
    }

    /**
     * Creates a thumbnail for the given media item.
     */
    public function createThumbnail(Media $media, ?string $source_dir = null): void
    {
        $source_dir ??= self::MEDIA_DIRECTORY;
        $source_path = $source_dir . $media->file_name;
        $base_name = pathinfo($media->file_name, PATHINFO_FILENAME);
        $thumb_dir = self::MEDIA_DIRECTORY_THUMBNAILS;

        if ($media->isImage()) {
            MediaThumbnail::createFromImage($source_path, $thumb_dir, $base_name);
        } else {
            $ext = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
            if ($ext === 'gif') {
                // GIFs: extract first frame as image thumbnail
                MediaThumbnail::createFromImage($source_path, $thumb_dir, $base_name);
            } else {
                MediaThumbnail::createFromVideo($source_path, $thumb_dir, $base_name);
            }
        }
    }

    /**
     * Generates a fingerprint for an image media item: a 64-bit perceptual hash
     * as a string of 0s and 1s. Only applicable to images — videos don't get
     * fingerprinted.
     *
     * Always hashed through GD, never whichever of GD or Imagick is loaded: the
     * two resample differently, and a fingerprint is only comparable with ones
     * computed the same way.
     */
    public function createFingerprint(Media $media, ?string $source_dir = null): string
    {
        if (!$media->isImage()) {
            return '';
        }

        $source_dir ??= self::MEDIA_DIRECTORY;
        $hasher = ImageHasher::create(GdDriver::class, new PerceptualStrategy());
        $bits = $hasher->hash($source_dir . $media->file_name)->toBits();
        $media->setBitsFingerprint($bits);

        return $bits;
    }

    /**
     * Detect the media type for a file on disk.
     * Returns 'image' or 'video'. Animated GIFs are classified as 'video'.
     */
    public static function detectMediaType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'gif') {
            return self::isAnimatedGif($filePath) ? 'video' : 'image';
        }

        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }

        return 'video';
    }

    /**
     * Check whether a GIF file contains multiple frames (i.e. is animated).
     * Scans for Graphic Control Extension headers (0x21 0xF9 0x04).
     * Two or more of these indicate animation.
     */
    private static function isAnimatedGif(string $filePath): bool
    {
        $fh = @fopen($filePath, 'rb');
        if ($fh === false) {
            return false;
        }

        $frames = 0;
        while (!feof($fh) && $frames < 2) {
            $chunk = fread($fh, 524288); // Read 512 KB at a time
            if ($chunk === false) {
                break;
            }
            // Graphic Control Extension: 0x21 (extension introducer),
            // 0xF9 (GCE label), 0x04 (block size — always 4)
            $frames += substr_count($chunk, "\x21\xF9\x04");
        }

        fclose($fh);
        return $frames > 1;
    }

    /**
     * Saves a media item to the database and generates thumbnail/fingerprint.
     *
     * @param Media       $media      The media item to save.
     * @param string|null $source_dir Directory containing the source file.
     *                                Defaults to MEDIA_DIRECTORY (the cron input folder).
     *                                Pass MEDIA_DIRECTORY_FULL when the file is already
     *                                in the full-size directory (e.g. web uploads).
     */
    public function save(Media $media, ?string $source_dir = null): int
    {
        $source_dir ??= self::MEDIA_DIRECTORY;

        // Generate fingerprint for images if not already set
        if ($media->isImage() && $media->bits_fingerprint === '') {
            $this->createFingerprint($media, $source_dir);
        }

        // Extract basic metadata (dimensions, duration, file size) from the source file
        $meta = MediaMetadata::extract($source_dir . $media->file_name, $media->media_type);
        $media->setWidth($meta['width'])
            ->setHeight($meta['height'])
            ->setDuration($meta['duration'])
            ->setFileSize($meta['file_size']);

        $media_id = $this->storage->store($media);

        if ($media_id > 0) {
            $media->setMediaId($media_id);
            $this->createThumbnail($media, $source_dir);
        }

        return $media->media_id;
    }

    /**
     * Re-extracts metadata from the media item's full-size file and persists it.
     * Used by the backfill script to populate metadata on pre-existing rows.
     */
    public function refreshMetadata(Media $media): bool
    {
        $path = self::MEDIA_DIRECTORY_FULL . $media->file_name;
        $meta = MediaMetadata::extract($path, $media->media_type);

        $media->setWidth($meta['width'])
            ->setHeight($meta['height'])
            ->setDuration($meta['duration'])
            ->setFileSize($meta['file_size']);

        return $this->storage->updateMetadata($media);
    }

    /**
     * Deletes a media item from the database and filesystem.
     *
     * @throws \OutOfBoundsException If the media item does not exist in the database.
     */
    public function delete(Media $media): bool
    {
        $success = $this->storage->delete($media);

        if ($success) {
            $file_path = self::MEDIA_DIRECTORY_FULL . $media->file_name;
            $base_name = pathinfo($media->file_name, PATHINFO_FILENAME);
            $thumb_1x = self::MEDIA_DIRECTORY_THUMBNAILS . $base_name . '.webp';
            $thumb_2x = self::MEDIA_DIRECTORY_THUMBNAILS . $base_name . '@2x.webp';

            if (file_exists($file_path)) {
                unlink($file_path);
            }
            if (file_exists($thumb_1x)) {
                unlink($thumb_1x);
            }
            if (file_exists($thumb_2x)) {
                unlink($thumb_2x);
            }
        } else {
            throw new OutOfBoundsException('Media not found in database.');
        }

        return true;
    }
}
