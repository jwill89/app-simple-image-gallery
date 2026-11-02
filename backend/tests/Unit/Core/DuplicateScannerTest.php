<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Core;

use Gallery\Core\DuplicateScanner;
use Gallery\Tests\Support\DatabaseTestCase;
use Gallery\Tests\Support\TestImages;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The scan's candidate and threshold logic, on fingerprints written straight to
 * the database, and its thumbnail pass on drawn thumbnails in a temp directory.
 */
#[CoversClass(DuplicateScanner::class)]
final class DuplicateScannerTest extends DatabaseTestCase
{
    private const string BASE_BITS = '0110100111010010101100011100101001101001110100101011000111001010';

    private string $reportDirectory;

    private string $thumbDirectory;

    protected function setUp(): void
    {
        $run = bin2hex(random_bytes(4));
        $this->reportDirectory = sys_get_temp_dir() . "/gallery-dupes-test-{$run}/";
        $this->thumbDirectory = sys_get_temp_dir() . "/gallery-thumbs-test-{$run}/";
    }

    protected function tearDown(): void
    {
        foreach ([$this->reportDirectory, $this->thumbDirectory] as $directory) {
            foreach ($this->filesIn($directory . '*') as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    private function addImage(PDO $db, string $bits, ?string $name = null): int
    {
        $db->prepare(
            "INSERT INTO media (media_type, file_name, file_time, hash, bits_fingerprint)
             VALUES ('image', :name, 1, :hash, :bits)"
        )->execute([':name' => $name ?? bin2hex(random_bytes(6)) . '.png', ':hash' => bin2hex(random_bytes(16)), ':bits' => $bits]);

        return (int) $db->lastInsertId();
    }

    /** Writes $image as the @2x thumbnail of a media item named "$name.png". */
    private function writeThumbnail(string $name, \GdImage $image): void
    {
        if (!is_dir($this->thumbDirectory)) {
            mkdir($this->thumbDirectory);
        }
        imagewebp($image, $this->thumbDirectory . $name . '@2x.webp', 90);
    }

    /**
     * BASE_BITS with $count bits flipped, one at the start of each LSH band in
     * turn: the worst case, where no band is left identical unless the scanner
     * sized its bands for the distance.
     */
    private function flipOnePerBand(int $count, int $bandBits): string
    {
        $bits = self::BASE_BITS;
        for ($i = 0; $i < $count; $i++) {
            $position = $i * $bandBits;
            $bits[$position] = $bits[$position] === '0' ? '1' : '0';
        }

        return $bits;
    }

    /**
     * @return list<array{int, int}> [id1, id2] of each reported pair
     */
    private function reportedPairs(DuplicateScanner $scanner): array
    {
        return array_map(static fn(array $match): array => [$match[0], $match[1]], $scanner->run()['matches']);
    }

    public function testBandsLeaveOneIntactForEveryDistanceWithinTheThreshold(): void
    {
        $this->assertSame(21, DuplicateScanner::bandBitsFor(64, 2));
        $this->assertSame(8, DuplicateScanner::bandBitsFor(64, 7));
        $this->assertSame(3, DuplicateScanner::bandBitsFor(64, 16));
        $this->assertSame(64, DuplicateScanner::bandBitsFor(64, 0));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function distances(): array
    {
        return ['default' => [2], 'loose' => [7], 'looser than 8-bit bands allow' => [10]];
    }

    #[DataProvider('distances')]
    public function testFindsAPairAtTheThresholdEvenWithEveryBandDisturbed(int $maxDistance): void
    {
        $db = self::makeDb();
        $bandBits = DuplicateScanner::bandBitsFor(64, $maxDistance);
        $base = $this->addImage($db, self::BASE_BITS);
        $atThreshold = $this->addImage($db, $this->flipOnePerBand($maxDistance, $bandBits));

        $scanner = new DuplicateScanner($db, $maxDistance, null, $this->reportDirectory);

        $this->assertSame([[$base, $atThreshold]], $this->reportedPairs($scanner));
    }

    #[DataProvider('distances')]
    public function testReportsNoPairOneBitPastTheThreshold(int $maxDistance): void
    {
        $db = self::makeDb();
        $this->addImage($db, self::BASE_BITS);
        $this->addImage($db, $this->flipOnePerBand($maxDistance + 1, 1));

        $scanner = new DuplicateScanner($db, $maxDistance, null, $this->reportDirectory);

        $this->assertSame([], $this->reportedPairs($scanner));
    }

    public function testRegionCheckKeepsPairsItCannotMeasure(): void
    {
        $db = self::makeDb();
        $a = $this->addImage($db, self::BASE_BITS);
        $b = $this->addImage($db, self::BASE_BITS);

        $result = new DuplicateScanner($db, 2, 0.0, $this->reportDirectory, thumbDirectory: $this->thumbDirectory)->run();

        $this->assertSame([[$a, $b, 0, null, null]], $result['matches']);
        $this->assertSame(0, $result['region_rejected']);
    }

    public function testRegionCheckRejectsAVariantAndKeepsAResavedCopy(): void
    {
        $artwork = TestImages::artwork();
        $this->writeThumbnail('original', $artwork);
        $this->writeThumbnail('variant', TestImages::variant($artwork));
        $this->writeThumbnail('resaved', TestImages::resaved($artwork, 0.9, 75));

        // All three share a fingerprint, as variants and re-saves do in practice.
        $db = self::makeDb();
        $original = $this->addImage($db, self::BASE_BITS, 'original.png');
        $this->addImage($db, self::BASE_BITS, 'variant.png');
        $resaved = $this->addImage($db, self::BASE_BITS, 'resaved.png');

        $result = new DuplicateScanner($db, reportDirectory: $this->reportDirectory, thumbDirectory: $this->thumbDirectory)->run();

        $this->assertSame([[$original, $resaved]], array_map(static fn(array $m): array => [$m[0], $m[1]], $result['matches']));
        $this->assertSame(2, $result['region_rejected']);
        $this->assertNotNull($result['matches'][0][3]);
        $this->assertNotNull($result['matches'][0][4]);
    }

    public function testTuningRunKeepsDismissedPairsAndLeavesTheReportAlone(): void
    {
        $db = self::makeDb();
        $a = $this->addImage($db, self::BASE_BITS);
        $b = $this->addImage($db, self::BASE_BITS);
        $db->prepare('INSERT INTO dismissed_duplicates (media_id_1, media_id_2, dismissed_at) VALUES (:a, :b, 1)')
            ->execute([':a' => $a, ':b' => $b]);

        $normal = new DuplicateScanner($db, 2, null, $this->reportDirectory)->run();
        $tuning = new DuplicateScanner($db, 2, null, $this->reportDirectory, true)->run();

        $this->assertSame([], $normal['matches']);
        $this->assertSame([[$a, $b, 0, null, null, true]], $tuning['matches']);
        $this->assertStringStartsWith($this->reportDirectory . 'tuning-', $tuning['report_file']);
        $this->assertCount(1, $this->filesIn($this->reportDirectory . 'dupes-*.json'));
    }
}
