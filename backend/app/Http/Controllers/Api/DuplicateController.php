<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DismissedDuplicate;
use App\Models\Media;
use App\Services\Duplicates\DuplicateScanner;
use App\Services\Duplicates\ScanSettings;
use App\Support\Timestamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JsonException;
use App\OpenApi\ProblemJson;
use OpenApi\Spec as OAS;
use Throwable;

/**
 * Duplicate detection: the latest scan's report, running a scan, and
 * dismissing a pair. No cached response depends on them.
 */
final class DuplicateController extends Controller
{
    /**
     * GET /duplicates/report — The latest duplicate-scan report, with dismissed
     * pairs filtered out and each pair enriched with media file names/hashes.
     */
    #[OAS\Operation\Get(
        path: '/duplicates/report',
        operationId: 'getDuplicateReport',
        summary: 'Latest duplicate report',
        tags: ['Duplicates'],
        responses: [
            new OAS\Response(response: 200, description: 'The report', content: new OAS\MediaType\Json(ref: '#/components/schemas/DuplicateReport')),
            new OAS\Response(response: 404, description: 'NoDupesDirectory, NoReportsFound', content: new ProblemJson()),
            new OAS\Response(response: 500, description: 'InvalidReport', content: new ProblemJson()),
        ],
    )]
    public function report(): JsonResponse
    {
        $directory = config()->string('gallery.dupes_path');
        if (!File::isDirectory($directory)) {
            throw new ApiException('NoDupesDirectory', 404, 'No duplicate scan has been run yet.');
        }

        // The newest dupes-*.json; a tuning run's report is never read here.
        $reports = File::glob($directory . '/dupes-*.json');
        usort($reports, static fn(string $a, string $b): int => File::lastModified($b) <=> File::lastModified($a));
        $latest = $reports[0] ?? throw new ApiException('NoReportsFound', 404, 'No duplicate reports found. Run a scan first.');

        try {
            $data = json_decode(File::get($latest), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('Duplicate report is corrupted', ['file' => basename($latest), 'error' => $e->getMessage()]);

            throw new ApiException('InvalidReport', 500, 'The latest report file is corrupted or unreadable.');
        }
        $data = is_array($data) ? $data : [];

        // Pairs dismissed since the scan, and pairs with an item deleted since,
        // are left out. A match is [id1, id2, distance, ssim, region difference];
        // an older report's has no ssim.
        $dismissed = DismissedDuplicate::pairKeys();
        $pairs = [];
        foreach (is_array($data['matches'] ?? null) ? $data['matches'] : [] as $match) {
            if (is_array($match) && !isset($dismissed[DismissedDuplicate::pairKey((int) $match[0], (int) $match[1])])) {
                $pairs[] = [(int) $match[0], (int) $match[1], $match[2] ?? null, $match[3] ?? null];
            }
        }
        $media = Media::query()->whereKey([...array_column($pairs, 0), ...array_column($pairs, 1)])->get()->keyBy('media_id');

        $matches = [];
        foreach ($pairs as [$id1, $id2, $distance, $ssim]) {
            $first = $media->get($id1);
            $second = $media->get($id2);
            if ($first !== null && $second !== null) {
                $matches[] = [
                    'media1' => self::summary($first),
                    'media2' => self::summary($second),
                    'distance' => $distance,
                    'ssim' => $ssim,
                ];
            }
        }

        return new JsonResponse([
            'reportFile' => basename($latest),
            'generatedAt' => $data['generated_at'] ?? null,
            'imagesCompared' => $data['images_compared'] ?? null,
            'duplicatesFound' => count($matches),
            'matches' => $matches,
        ]);
    }

    /**
     * POST /duplicates/scan — Run the perceptual-hash duplicate scanner and
     * return summary stats (images compared, candidates, duplicates found).
     */
    #[OAS\Operation\Post(
        path: '/duplicates/scan',
        operationId: 'runDuplicateScan',
        summary: 'Run a duplicate scan',
        tags: ['Duplicates'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(response: 200, description: 'Scan stats', content: new OAS\MediaType\Json(ref: '#/components/schemas/ScanResult')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 500, description: 'ScanFailed', content: new ProblemJson()),
        ],
    )]
    public function scan(DuplicateScanner $scanner): JsonResponse
    {
        Log::info('Duplicate scan initiated');

        try {
            $result = $scanner->run(ScanSettings::fromConfig());
        } catch (Throwable $e) {
            Log::error('Duplicate scan failed', ['error' => $e->getMessage()]);

            throw new ApiException('ScanFailed', 500, 'The duplicate scan encountered an error. Check the server logs for details.');
        }
        Log::info('Duplicate scan completed', array_diff_key($result, ['matches' => true]));

        return new JsonResponse([
            'success' => true,
            'message' => 'Scan completed successfully.',
            'imagesCompared' => $result['images_compared'],
            'lshCandidates' => $result['lsh_candidates'],
            'duplicatesFound' => $result['duplicates_found'],
            'executionTime' => $result['execution_time_seconds'],
        ]);
    }

    /**
     * POST /duplicates/dismissals — Dismiss a pair of media items as not-duplicates.
     * Body: { mediaId1, mediaId2 }.
     */
    #[OAS\Operation\Post(
        path: '/duplicates/dismissals',
        operationId: 'dismissDuplicatePair',
        summary: 'Dismiss a duplicate pair',
        tags: ['Duplicates'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/DismissalBody')),
        responses: [
            new OAS\Response(response: 201, description: 'Dismissed', content: new OAS\MediaType\Json(ref: '#/components/schemas/DismissResult')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput, MediaNotFound (an id with no item)', content: new ProblemJson()),
        ],
    )]
    public function dismiss(Request $request): JsonResponse
    {
        $id1 = $request->integer('mediaId1');
        $id2 = $request->integer('mediaId2');
        if ($id1 <= 0 || $id2 <= 0 || $id1 === $id2) {
            throw self::invalid('InvalidInput', 'Two different, valid media IDs are required.');
        }
        if (Media::query()->whereKey([$id1, $id2])->count() !== 2) {
            throw self::invalid('MediaNotFound', 'One or both media items could not be found.');
        }

        // Dismissing a pair again is not an error.
        $pair = ['media_id_1' => min($id1, $id2), 'media_id_2' => max($id1, $id2)];
        DismissedDuplicate::query()->insertOrIgnore([...$pair, 'dismissed_at' => Timestamp::now()]);
        Log::info('Duplicate pair dismissed', $pair);

        return new JsonResponse(['dismissed' => true, 'mediaId1' => $pair['media_id_1'], 'mediaId2' => $pair['media_id_2']], 201);
    }

    /** @return array{mediaId: int, fileName: string, hash: string} */
    private static function summary(Media $media): array
    {
        return ['mediaId' => $media->media_id, 'fileName' => $media->file_name, 'hash' => $media->hash];
    }

    private static function invalid(string $code, string $message): ApiException
    {
        return new ApiException($code, 422, $message, [], [['message' => $message, 'location' => 'body.mediaId1']]);
    }
}
