<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\Danbooru\DanbooruTagger;
use App\Services\Media\MediaIngest;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;
use Throwable;

/**
 * Uploads: each file is checked and added on its own, and reported on in the
 * results, so one bad file doesn't fail the rest. The request is 201 whatever
 * happened to its files.
 */
final class UploadController extends Controller
{
    // PHP's upload_max_filesize (.htaccess) refuses anything over 100 MB first.
    private const int MAX_FILE_SIZE = 500 * 1024 * 1024;

    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * POST /media — Upload one or more files (multipart/form-data with files[]).
     * Media type is auto-detected from each file's extension.
     */
    #[OAS\Operation\Post(
        path: '/media',
        operationId: 'uploadMedia',
        summary: 'Upload media (create)',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAS\Schema(
                    properties: [
                        new OAS\Property(property: 'files[]', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'string', format: 'binary'))),
                        new OAS\Property(property: 'fetch_tags', schema: new OAS\Schema(type: 'boolean', description: 'Fetch Danbooru tags for uploaded images.')),
                    ],
                ),
            ),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'Upload result', content: new OAS\MediaType\Json(ref: '#/components/schemas/UploadSummary')),
            new OAS\Response(response: 400, description: 'NoFilesUploaded', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(Request $request, MediaIngest $ingest, DanbooruTagger $tagger): JsonResponse
    {
        $files = array_filter(array_values((array) $request->file('files', [])), static fn(mixed $file): bool => $file instanceof UploadedFile);
        if ($files === []) {
            throw new ApiException('NoFilesUploaded', 400, 'No files were included in the upload request.');
        }

        // Asked for without Danbooru credentials, it is ignored.
        $fetchTags = $request->boolean('fetch_tags') && DanbooruTagger::isConfigured();

        $results = [];
        $tagsApplied = 0;
        foreach ($files as $file) {
            $result = $this->upload($file, $ingest);
            if ($fetchTags && isset($result['id'])) {
                $result = [...$result, ...$this->importTags($tagger, $result['id'])];
                $tagsApplied += $result['tags_applied'] ?? 0;
            }
            $results[] = $result;
        }

        $statuses = array_count_values(array_column($results, 'status'));
        $uploaded = $statuses['success'] ?? 0;
        $duplicates = $statuses['duplicate'] ?? 0;
        if ($uploaded > 0) {
            $this->cache->bump(CacheGroup::Media);
        }
        if ($tagsApplied > 0) {
            $this->cache->bump(CacheGroup::Tags);
        }

        return new JsonResponse([
            'results' => $results,
            'total_uploaded' => $uploaded,
            'total_duplicates' => $duplicates,
            'total_failed' => count($results) - $uploaded - $duplicates,
            ...($fetchTags ? ['total_tags_applied' => $tagsApplied] : []),
        ], 201);
    }

    /**
     * Check one file, move it into media/full/ under a safe, unused name, and
     * add it, unless it is a copy of an item there already. The result names
     * the file as uploaded, or as saved once it is.
     *
     * @return array{file_name: string, status: string, id?: int, existing_id?: int, hash?: string, message?: string}
     */
    private function upload(UploadedFile $file, MediaIngest $ingest): array
    {
        $originalName = $file->getClientOriginalName();
        $error = static fn(string $message): array => ['file_name' => $originalName, 'status' => 'error', 'message' => $message];

        if (!$file->isValid()) {
            return $error('Upload error code: ' . $file->getError());
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!Media::isSupportedFile($originalName)) {
            return $error('Invalid file type: .' . $extension);
        }
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $error('File too large (max 500 MB)');
        }

        // Anything outside [a-zA-Z0-9._-] becomes "_"; a name in use gets _1, _2, …
        // before its (lowercased) extension.
        $directory = config()->string('gallery.media_path') . '/full';
        $name = (string) preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $base = pathinfo($name, PATHINFO_FILENAME);
        for ($counter = 1; File::exists("{$directory}/{$name}"); $counter++) {
            $name = "{$base}_{$counter}.{$extension}";
        }

        try {
            $path = $file->move($directory, $name)->getPathname();
        } catch (Throwable $e) {
            Log::error('Upload move failed', ['file' => $originalName, 'error' => $e->getMessage()]);

            return $error('Failed to save file');
        }

        // The content, not just the extension, must be an image or a video, so
        // a script disguised with a media extension never stays in the publicly
        // served folder.
        $mimeType = (string) File::mimeType($path);
        if (!str_starts_with($mimeType, 'image/') && !str_starts_with($mimeType, 'video/')) {
            File::delete($path);
            Log::warning('Upload rejected: content type mismatch', ['file' => $originalName, 'detected_mime' => $mimeType]);

            return $error('File content is not a valid image or video.');
        }

        $hash = md5_file($path);
        if ($hash === false) {
            File::delete($path);

            return $error('Could not read the uploaded file.');
        }
        $existingId = Media::query()->where('hash', $hash)->value('media_id');
        if (is_int($existingId)) {
            File::delete($path);

            return ['file_name' => $originalName, 'status' => 'duplicate', 'existing_id' => $existingId, 'hash' => $hash];
        }

        try {
            $media = $ingest->add($path, $name, $hash, now()->getTimestamp());
        } catch (Throwable $e) {
            File::delete($path);
            Log::error('Upload processing failed', ['file' => $originalName, 'error' => $e->getMessage()]);

            return $error('Processing failed: ' . $e->getMessage());
        }

        return ['file_name' => $name, 'status' => 'success', 'id' => $media->media_id, 'hash' => $hash];
    }

    /**
     * Look a new item up on Danbooru and apply its tags. A failed lookup is
     * reported on the file, not the upload, with the reason: Danbooru failing
     * (refused, blocked, unreachable) is not the same as it having no post.
     *
     * @return array{tags_found: bool, tags_applied: int, tags_method: string}|array{tags_error: string}
     */
    private function importTags(DanbooruTagger $tagger, int $mediaId): array
    {
        try {
            $import = $tagger->importForMedia(Media::query()->findOrFail($mediaId));
        } catch (Throwable $e) {
            Log::warning('Danbooru tag import failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);

            return ['tags_error' => 'Tag fetch failed'];
        }

        $failure = $import->failureException();
        if ($failure !== null) {
            Log::warning('Danbooru tag import failed', ['media_id' => $mediaId, 'reason' => $import->failure?->name, 'ray' => $import->ray]);

            return ['tags_error' => $failure->getMessage()];
        }

        return ['tags_found' => $import->found, 'tags_applied' => $import->tagsApplied, 'tags_method' => $import->method];
    }
}
