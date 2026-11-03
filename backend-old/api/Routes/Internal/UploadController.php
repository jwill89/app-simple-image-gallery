<?php

namespace Routes\Internal;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Collection\MediaCollection;
use Gallery\Core\CacheGroup;
use Gallery\Core\DanbooruTagger;
use Gallery\Structure\Media;
use OpenApi\Spec as OAS;

/**
 * UploadController class
 * Handles authenticated file uploads for all media types (the RESTful create
 * of a media resource — POST /media, multipart/form-data).
 * Files are saved directly to the appropriate full/ subdirectory.
 */
class UploadController extends AbstractController
{
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif'];
    private const array VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v'];
    private const int MAX_FILE_SIZE = 500 * 1024 * 1024; // 500 MB

    public function __construct(
        private readonly MediaCollection $media_collection,
        // The tagger is lazy: it only warms its DB caches on first import, so
        // injecting it here is cheap even when an upload doesn't fetch tags.
        private readonly DanbooruTagger $tagger
    ) {
        parent::__construct();
    }

    /**
     * POST /media — Upload one or more files (multipart/form-data with files[]).
     * Media type is auto-detected from each file's extension.
     */
    #[OAS\Operation\Post(
        path: '/media',
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
                    ]
                )
            )
        ),
        responses: [
            new OAS\Response(response: 201, description: 'Upload result', content: new OAS\MediaType\Json(ref: '#/components/schemas/UploadSummary')),
            new OAS\Response(response: 400, description: 'NoFilesUploaded', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function create(Request $request, Response $response): Response
    {
        $params = $this->parsedBody($request);

        $uploaded_files = $request->getUploadedFiles();
        $files = $uploaded_files['files'] ?? [];

        if (!is_array($files)) {
            $files = [$files];
        }

        if ($files === []) {
            return $this->error($response, 'NoFilesUploaded', 400, 'No files were included in the upload request.');
        }

        // Check if Danbooru tag fetching was requested (applies to images only)
        $fetchTags = (bool) ($params['fetch_tags'] ?? false) && DanbooruTagger::isConfigured();

        $all_extensions = array_merge(self::IMAGE_EXTENSIONS, self::VIDEO_EXTENSIONS);
        $target_dir = MediaCollection::getFullDirectory();

        $tagger = $fetchTags ? $this->tagger : null;
        $totalTagsApplied = 0;

        $results = [];

        foreach ($files as $file) {
            /** @var \Psr\Http\Message\UploadedFileInterface $file */
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $results[] = [
                    'file_name' => $file->getClientFilename(),
                    'status' => 'error',
                    'message' => 'Upload error code: ' . $file->getError(),
                ];
                continue;
            }

            $original_name = $file->getClientFilename() ?? '';
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            if (!in_array($ext, $all_extensions, true)) {
                $results[] = [
                    'file_name' => $original_name,
                    'status' => 'error',
                    'message' => 'Invalid file type: .' . $ext,
                ];
                continue;
            }

            if ($file->getSize() > self::MAX_FILE_SIZE) {
                $results[] = [
                    'file_name' => $original_name,
                    'status' => 'error',
                    'message' => 'File too large (max 500 MB)',
                ];
                continue;
            }

            $safe_name = (string) preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);

            $base = pathinfo($safe_name, PATHINFO_FILENAME);
            $dest_path = $target_dir . $safe_name;
            $counter = 1;
            while (file_exists($dest_path)) {
                $safe_name = $base . '_' . $counter . '.' . $ext;
                $dest_path = $target_dir . $safe_name;
                $counter++;
            }

            try {
                $file->moveTo($dest_path);
            } catch (\Exception $e) {
                $results[] = [
                    'file_name' => $original_name,
                    'status' => 'error',
                    'message' => 'Failed to save file',
                ];
                $this->logger->error('Upload move failed', [
                    'file' => $original_name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // Defense-in-depth: verify the file's actual content type, not just
            // its extension, so a script/polyglot disguised with a media
            // extension can't land in the publicly-served media directory.
            if (class_exists('finfo')) {
                $mime = (string) new \finfo(FILEINFO_MIME_TYPE)->file($dest_path);
                if (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/')) {
                    unlink($dest_path);
                    $results[] = [
                        'file_name' => $original_name,
                        'status' => 'error',
                        'message' => 'File content is not a valid image or video.',
                    ];
                    $this->logger->warning('Upload rejected: content type mismatch', [
                        'file' => $original_name,
                        'detected_mime' => $mime,
                    ]);
                    continue;
                }
            }

            // Compute MD5 hash and check for duplicates
            $md5 = md5_file($dest_path);
            if ($md5 === false) {
                unlink($dest_path);
                $results[] = [
                    'file_name' => $original_name,
                    'status' => 'error',
                    'message' => 'Could not read the uploaded file.',
                ];
                continue;
            }
            $existing_id = $this->media_collection->findIdByHash($md5);

            if ($existing_id !== null) {
                unlink($dest_path);
                $results[] = [
                    'file_name' => $original_name,
                    'status' => 'duplicate',
                    'existing_id' => $existing_id,
                    'hash' => $md5,
                ];
                continue;
            }

            // Auto-detect media type (animated GIFs → 'video', everything else by extension)
            $media_type = MediaCollection::detectMediaType($dest_path);

            try {
                $item = new Media();
                $item->setMediaType($media_type)
                    ->setFileName($safe_name)
                    ->setFileTime(time())
                    ->setHash($md5);

                $id = $this->media_collection->save($item, $target_dir);

                if ($id > 0) {
                    $result_entry = [
                        'file_name' => $safe_name,
                        'status' => 'success',
                        'id' => $id,
                        'hash' => $md5,
                    ];

                    // Fetch and apply Danbooru tags if requested
                    if ($tagger !== null) {
                        try {
                            $tagResult = $tagger->importTagsForMedia($id, $md5, $safe_name);
                            $result_entry['tags_found'] = $tagResult['found'];
                            $result_entry['tags_applied'] = $tagResult['tags_applied'];
                            $result_entry['tags_method'] = $tagResult['method'];
                            $totalTagsApplied += $tagResult['tags_applied'];
                        } catch (\Throwable $e) {
                            $this->logger->warning('Danbooru tag import failed', [
                                'media_id' => $id,
                                'error' => $e->getMessage(),
                            ]);
                            $result_entry['tags_error'] = 'Tag fetch failed';
                        }
                    }

                    $results[] = $result_entry;
                } else {
                    unlink($dest_path);
                    $results[] = [
                        'file_name' => $original_name,
                        'status' => 'error',
                        'message' => 'Failed to save to database',
                    ];
                }
            } catch (\Exception $e) {
                if (file_exists($dest_path)) {
                    unlink($dest_path);
                }
                $results[] = [
                    'file_name' => $original_name,
                    'status' => 'error',
                    'message' => 'Processing failed: ' . $e->getMessage(),
                ];
                $this->logger->error('Upload processing failed', [
                    'file' => $original_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $succeeded = count(array_filter($results, fn($r) => $r['status'] === 'success'));
        $duplicates = count(array_filter($results, fn($r) => $r['status'] === 'duplicate'));
        $failed = count($results) - $succeeded - $duplicates;

        if ($succeeded > 0) {
            $groups = [CacheGroup::Media];
            if ($totalTagsApplied > 0) {
                $groups[] = CacheGroup::Tags;
            }
            $this->invalidateCache(...$groups);
        }

        $responseData = [
            'results' => $results,
            'total_uploaded' => $succeeded,
            'total_duplicates' => $duplicates,
            'total_failed' => $failed,
        ];

        if ($fetchTags) {
            $responseData['total_tags_applied'] = $totalTagsApplied;
        }

        return $this->created($response, $responseData);
    }
}
