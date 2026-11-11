<?php

declare(strict_types=1);

use App\Models\DismissedDuplicate;
use App\Models\Media;
use App\Services\Duplicates\DuplicateScanner;
use App\Services\Duplicates\ScanSettings;
use Illuminate\Support\Facades\File;
use Tests\Support\ContractFixture;
use Tests\Support\ContractFixtureSeeder;
use Tests\Support\TestImages;

use function Pest\Laravel\seed;

// The scan's candidate and threshold logic on fingerprints written straight to
// the database, its thumbnail pass on drawn thumbnails, and its answers on the
// contract fixture, which must match the recordings'.

const SCAN_BASE_BITS = '0110100111010010101100011100101001101001110100101011000111001010';

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/gallery-scan-test-' . bin2hex(random_bytes(4));
    config(['gallery.media_path' => $root . '/media', 'gallery.dupes_path' => $root . '/dupes']);
});

afterEach(function (): void {
    File::deleteDirectory(dirname(config()->string('gallery.media_path')));
});

function scanImage(string $bits, ?string $fileName = null): int
{
    return Media::factory()->create(['bits_fingerprint' => $bits] + ($fileName === null ? [] : ['file_name' => $fileName]))->media_id;
}

/** Writes $image as the @2x thumbnail of a media item named "$name.png". */
function scanThumbnail(string $name, GdImage $image): void
{
    $directory = config()->string('gallery.media_path') . '/thumbs';
    File::ensureDirectoryExists($directory);
    imagewebp($image, "{$directory}/{$name}@2x.webp", 90);
}

/**
 * SCAN_BASE_BITS with $count bits flipped, one at the start of each LSH band in
 * turn: the worst case, where no band is left identical unless the scanner
 * sized its bands for the distance.
 */
function flipOnePerBand(int $count, int $bandBits): string
{
    $bits = SCAN_BASE_BITS;
    for ($i = 0; $i < $count; $i++) {
        $position = $i * $bandBits;
        $bits[$position] = $bits[$position] === '0' ? '1' : '0';
    }

    return $bits;
}

/**
 * [id1, id2] of each pair the scan reports.
 *
 * @return list<array{int, int}>
 */
function reportedPairs(ScanSettings $settings): array
{
    return array_map(static fn(array $match): array => [$match[0], $match[1]], app(DuplicateScanner::class)->run($settings)['matches']);
}

test('bands leave one intact for every distance within the threshold', function (): void {
    expect(DuplicateScanner::bandBitsFor(64, 2))->toBe(21)
        ->and(DuplicateScanner::bandBitsFor(64, 7))->toBe(8)
        ->and(DuplicateScanner::bandBitsFor(64, 16))->toBe(3)
        ->and(DuplicateScanner::bandBitsFor(64, 0))->toBe(64);
});

dataset('distances', ['default' => [2], 'loose' => [7], 'looser than 8-bit bands allow' => [10]]);

test('finds a pair at the threshold even with every band disturbed', function (int $maxDistance): void {
    $base = scanImage(SCAN_BASE_BITS);
    $atThreshold = scanImage(flipOnePerBand($maxDistance, DuplicateScanner::bandBitsFor(64, $maxDistance)));

    expect(reportedPairs(new ScanSettings($maxDistance, null)))->toBe([[$base, $atThreshold]]);
})->with('distances');

test('reports no pair one bit past the threshold', function (int $maxDistance): void {
    scanImage(SCAN_BASE_BITS);
    scanImage(flipOnePerBand($maxDistance + 1, 1));

    expect(reportedPairs(new ScanSettings($maxDistance, null)))->toBe([]);
})->with('distances');

test('the region check keeps pairs it cannot measure', function (): void {
    $a = scanImage(SCAN_BASE_BITS);
    $b = scanImage(SCAN_BASE_BITS);

    $result = app(DuplicateScanner::class)->run(new ScanSettings(2, 0.0));

    expect($result['matches'])->toBe([[$a, $b, 0, null, null]])
        ->and($result['region_rejected'])->toBe(0);
});

test('the region check rejects a variant and keeps a resaved copy', function (): void {
    $artwork = TestImages::artwork();
    scanThumbnail('original', $artwork);
    scanThumbnail('variant', TestImages::variant($artwork));
    scanThumbnail('resaved', TestImages::resaved($artwork, 0.9, 75));

    // All three share a fingerprint, as variants and re-saves do in practice.
    $original = scanImage(SCAN_BASE_BITS, 'original.png');
    scanImage(SCAN_BASE_BITS, 'variant.png');
    $resaved = scanImage(SCAN_BASE_BITS, 'resaved.png');

    $result = app(DuplicateScanner::class)->run(new ScanSettings());

    expect(array_map(static fn(array $m): array => [$m[0], $m[1]], $result['matches']))->toBe([[$original, $resaved]])
        ->and($result['region_rejected'])->toBe(2)
        ->and($result['matches'][0][3])->not->toBeNull()
        ->and($result['matches'][0][4])->not->toBeNull();
});

test('a tuning run keeps dismissed pairs and leaves the report alone', function (): void {
    $a = scanImage(SCAN_BASE_BITS);
    $b = scanImage(SCAN_BASE_BITS);
    DismissedDuplicate::insert(['media_id_1' => $a, 'media_id_2' => $b, 'dismissed_at' => 1]);

    $normal = app(DuplicateScanner::class)->run(new ScanSettings(2, null));
    $tuning = app(DuplicateScanner::class)->run(new ScanSettings(2, null, tuning: true));

    expect($normal['matches'])->toBe([])
        ->and($tuning['matches'])->toBe([[$a, $b, 0, null, null, true]])
        ->and($tuning['report_file'])->toStartWith(config()->string('gallery.dupes_path') . '/tuning-')
        ->and(File::glob(config()->string('gallery.dupes_path') . '/dupes-*.json'))->toHaveCount(1);
});

test('the contract fixture scans as the Slim app recorded it', function (string $case, ScanSettings $settings): void {
    seed(ContractFixtureSeeder::class);
    File::copyDirectory(ContractFixture::path('media/thumbs'), config()->string('gallery.media_path') . '/thumbs');

    /** @var list<array{request: array{path: string}, response: array{json: array<string, mixed>}}> $steps */
    $steps = ContractFixture::recording($case)['steps'];
    $recorded = static fn(string $path): array => collect($steps)->firstOrFail(fn(array $step): bool => $step['request']['path'] === $path)['response']['json'];
    $scan = $recorded('/api/duplicates/scan');
    $report = $recorded('/api/duplicates/report');

    $result = app(DuplicateScanner::class)->run($settings);

    expect($result['images_compared'])->toBe($scan['images_compared'])
        ->and($result['lsh_candidates'])->toBe($scan['lsh_candidates'])
        ->and($result['duplicates_found'])->toBe($scan['duplicates_found']);

    /** @var list<array{media_1: array{media_id: int}, media_2: array{media_id: int}, distance: int, ssim: ?float}> $matches */
    $matches = $report['matches'];
    expect(array_map(static fn(array $m): array => [$m[0], $m[1], $m[2]], $result['matches']))
        ->toBe(array_map(static fn(array $m): array => [$m['media_1']['media_id'], $m['media_2']['media_id'], $m['distance']], $matches));

    // SSIM decodes the WebP thumbnails, whose last digits can vary by libwebp build.
    foreach ($matches as $i => $match) {
        expect($result['matches'][$i][3])->toEqualWithDelta($match['ssim'], 0.001);
    }
})->with([
    'default settings' => ['duplicates/scan', new ScanSettings()],
    'region check off' => ['duplicates/scan-region-check-off', new ScanSettings(maxRegionDiff: null)],
    'wider cutoff' => ['duplicates/scan-wider-cutoff', new ScanSettings(maxDistance: 8)],
]);
